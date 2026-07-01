const dayjs = require("dayjs");
const {generateBookingId} = require("../utils/bookingId");
const {applyOverlapCondition} = require("../utils/overlap");
const {
  calculateVehiclePricing,
  getActiveVehicleById,
  getBookingDateTimes,
  getDaysType,
} = require("./vehicleService");
const {
  RENTAL_STATUS,
  BLOCKING_RENTAL_STATUSES,
  ADMIN_APPROVABLE_RENTAL_STATUSES,
  ADMIN_CANCELLABLE_RENTAL_STATUSES,
  USER_CANCELLABLE_RENTAL_STATUSES,
} = require("../utils/rentalStatus");
const {
  deleteRemindersForRental,
  scheduleRemindersForRental,
} = require("../utils/reminders");

class RentalServiceError extends Error {
  constructor(code, meta = {}) {
    super(code);
    this.name = "RentalServiceError";
    this.code = code;
    this.meta = meta;
  }
}

function parseUserMeta(meta) {
  if (!meta) return {};
  if (typeof meta === "object") return meta;
  try {
    return JSON.parse(meta);
  } catch (e) {
    return {};
  }
}

function hasPassportInfo(user) {
  const meta = parseUserMeta(user.meta);
  return Boolean(user.passport_photo_file_id || meta.passport_number);
}

function assertDraftBookingInput(booking) {
  if (
    !booking ||
    !booking.startDate ||
    !booking.endDate ||
    !booking.selectedBikeId ||
    (!booking.priceUnknown && booking.totalPrice == null)
  ) {
    throw new RentalServiceError("booking_not_enough_data");
  }
}

async function findOverlappingRental(
  client,
  {bikeId, startAt, endAt, startDate, endDate, excludeRentalId = null}
) {
  const query = client("rentals")
    .where("bike_id", bikeId)
    .whereIn("status", BLOCKING_RENTAL_STATUSES)
    .andWhere((qb) =>
      applyOverlapCondition(qb, startAt, endAt, startDate, endDate)
    );

  if (excludeRentalId) {
    query.andWhere("id", "!=", excludeRentalId);
  }

  return query.first();
}

async function createDraftRental(client, {user, booking}) {
  if (!user?.id) {
    throw new RentalServiceError("user_not_found");
  }
  assertDraftBookingInput(booking);

  const {startAtIso, endAtIso} = getBookingDateTimes(booking);

  return client.transaction(async (trx) => {
    await trx.raw("select pg_advisory_xact_lock(?)", [Number(user.id)]);

    const vehicle = await getActiveVehicleById(trx, booking.selectedBikeId);
    if (!vehicle) {
      throw new RentalServiceError("bike_not_found");
    }

    const existingRental = await trx("rentals")
      .where({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        status: RENTAL_STATUS.PROCESS,
      })
      .first();

    if (existingRental) {
      return {created: false, rental: existingRental, vehicle, bike: vehicle};
    }

    const conflict = await findOverlappingRental(trx, {
      bikeId: booking.selectedBikeId,
      startAt: startAtIso,
      endAt: endAtIso,
      startDate: booking.startDate,
      endDate: booking.endDate,
    });

    if (conflict) {
      throw new RentalServiceError("overlap_conflict", {
        conflictRentalId: conflict.id,
      });
    }

    const rows = await trx("rentals")
      .insert({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        start_date: booking.startDate,
        end_date: booking.endDate,
        start_at: startAtIso,
        end_at: endAtIso,
        total_price: booking.priceUnknown ? null : booking.totalPrice,
        status: RENTAL_STATUS.PROCESS,
        docs_missing: !hasPassportInfo(user),
        helmets_qty: booking.helmets || 0,
        delivery_required: Boolean(booking.deliveryRequired),
        delivery_address: booking.deliveryAddress || null,
        comment: booking.notes || null,
        booking_public_id: generateBookingId(),
        deposit_required: booking.deposit || null,
      })
      .returning("*");

    return {created: true, rental: rows[0], vehicle, bike: vehicle};
  });
}

async function getDraftRentalsForUser(client, userId) {
  return client("rentals")
    .where("user_id", userId)
    .andWhere("status", RENTAL_STATUS.PROCESS);
}

async function confirmDraftRentals(client, {userId}) {
  return client.transaction(async (trx) => {
    const rentalsToConfirm = await trx("rentals")
      .where("user_id", userId)
      .andWhere("status", RENTAL_STATUS.PROCESS)
      .forUpdate();

    if (!rentalsToConfirm.length) {
      return [];
    }

    for (const rental of rentalsToConfirm) {
      const conflict = await findOverlappingRental(trx, {
        bikeId: rental.bike_id,
        startAt: rental.start_at,
        endAt: rental.end_at,
        startDate: rental.start_date,
        endDate: rental.end_date,
        excludeRentalId: rental.id,
      });

      if (conflict) {
        throw new RentalServiceError("overlap_conflict", {
          rentalId: rental.id,
          conflictRentalId: conflict.id,
        });
      }
    }

    const now = dayjs().toISOString();
    const rentalIds = rentalsToConfirm.map((rental) => rental.id);
    const updatedCount = await trx("rentals")
      .whereIn("id", rentalIds)
      .andWhere("user_id", userId)
      .andWhere("status", RENTAL_STATUS.PROCESS)
      .update({
        status: RENTAL_STATUS.PENDING,
        accept_terms: true,
        confirmed_at: now,
        updated_at: now,
      });

    if (!updatedCount) {
      return [];
    }

    return trx("rentals")
      .whereIn("id", rentalIds)
      .andWhere("user_id", userId)
      .andWhere("status", RENTAL_STATUS.PENDING);
  });
}

async function approveRental(client, {rentalId}) {
  return client.transaction(async (trx) => {
    const rental = await trx("rentals").where({id: rentalId}).forUpdate().first();
    if (!rental) {
      return {status: "not_found", rental: null};
    }
    if (!ADMIN_APPROVABLE_RENTAL_STATUSES.includes(rental.status)) {
      return {status: "status_changed", rental};
    }

    const conflict = await findOverlappingRental(trx, {
      bikeId: rental.bike_id,
      startAt: rental.start_at,
      endAt: rental.end_at,
      startDate: rental.start_date,
      endDate: rental.end_date,
      excludeRentalId: rental.id,
    });
    if (conflict) {
      throw new RentalServiceError("overlap_conflict", {
        rentalId: rental.id,
        conflictRentalId: conflict.id,
      });
    }

    const rows = await trx("rentals")
      .where({id: rentalId})
      .whereIn("status", ADMIN_APPROVABLE_RENTAL_STATUSES)
      .update({
        status: RENTAL_STATUS.APPROVED,
        updated_at: dayjs().toISOString(),
      })
      .returning("*");

    if (!rows.length) {
      return {status: "status_changed", rental};
    }

    await scheduleRemindersForRental(trx, rentalId);
    return {status: "approved", rental: rows[0]};
  });
}

async function cancelRentalByAdmin(client, {rentalId}) {
  return client.transaction(async (trx) => {
    const rental = await trx("rentals").where({id: rentalId}).forUpdate().first();
    if (!rental) {
      return {status: "not_found", rental: null};
    }
    if (!ADMIN_CANCELLABLE_RENTAL_STATUSES.includes(rental.status)) {
      return {status: "status_changed", rental};
    }

    const rows = await trx("rentals")
      .where({id: rentalId})
      .whereIn("status", ADMIN_CANCELLABLE_RENTAL_STATUSES)
      .update({
        status: RENTAL_STATUS.CANCELLED,
        updated_at: dayjs().toISOString(),
      })
      .returning("*");

    if (!rows.length) {
      return {status: "status_changed", rental};
    }

    await deleteRemindersForRental(trx, rentalId);
    return {status: "cancelled", rental: rows[0]};
  });
}

async function cancelRentalByUser(client, {rentalId, userId}) {
  return client.transaction(async (trx) => {
    const rental = await trx("rentals")
      .where({id: rentalId, user_id: userId})
      .forUpdate()
      .first();
    if (!rental) {
      return {status: "not_found", rental: null};
    }
    if (!USER_CANCELLABLE_RENTAL_STATUSES.includes(rental.status)) {
      return {status: "status_changed", rental};
    }

    const rows = await trx("rentals")
      .where({id: rentalId, user_id: userId})
      .whereIn("status", USER_CANCELLABLE_RENTAL_STATUSES)
      .update({
        status: RENTAL_STATUS.CANCELLED_BY_CLIENT,
        updated_at: dayjs().toISOString(),
      })
      .returning("*");

    if (!rows.length) {
      return {status: "status_changed", rental};
    }

    await deleteRemindersForRental(trx, rentalId);
    return {status: "cancelled", rental: rows[0]};
  });
}

async function calculateBikePricing(client, {bikeId, ...params}) {
  return calculateVehiclePricing(client, {vehicleId: bikeId, ...params});
}

module.exports = {
  RentalServiceError,
  assertDraftBookingInput,
  calculateBikePricing,
  calculateVehiclePricing,
  cancelRentalByAdmin,
  cancelRentalByUser,
  confirmDraftRentals,
  createDraftRental,
  findOverlappingRental,
  getBookingDateTimes,
  getDraftRentalsForUser,
  getDaysType,
  approveRental,
};
