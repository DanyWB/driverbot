const assert = require("assert/strict");
const db = require("../connect");
const {
  approveRental,
  calculateBikePricing,
  cancelRentalByAdmin,
  cancelRentalByUser,
  confirmDraftRentals,
  createDraftRental,
} = require("../services/rentalService");
const {RENTAL_STATUS} = require("../utils/rentalStatus");
const {VEHICLE_TYPE} = require("../utils/vehicleTypes");

const ROLLBACK = new Error("rollback_rental_service_check");

async function insertReturningFirst(query) {
  const rows = await query.returning("*");
  return rows[0];
}

async function seed(trx) {
  const suffix = `${Date.now()}_${Math.floor(Math.random() * 100000)}`;
  const category = await insertReturningFirst(
    trx("categories").insert({name: `__check_category_${suffix}`})
  );
  const bike = await insertReturningFirst(
    trx("bikes").insert({
      name: "Rental service check car",
      category_id: category.id,
      description: "Temporary car for rental service check",
      vehicle_type: VEHICLE_TYPE.CAR,
      is_active: true,
    })
  );
  const userA = await insertReturningFirst(
    trx("users").insert({
      telegram_id: Number(`${Date.now()}1`),
      telegram_name: "rental_check_a",
      name: "Rental Check A",
      phone: "+66000000001",
      meta: {passport_number: "CHECK-A"},
    })
  );
  const userB = await insertReturningFirst(
    trx("users").insert({
      telegram_id: Number(`${Date.now()}2`),
      telegram_name: "rental_check_b",
      name: "Rental Check B",
      phone: "+66000000002",
      meta: {passport_number: "CHECK-B"},
    })
  );
  const seasons = await trx("seasons").select("id", "months");
  let season = seasons.find((row) => row.months.includes(1));
  if (!season) {
    season = await insertReturningFirst(
      trx("seasons").insert({
      name: `__check_season_${suffix}`,
      months: trx.raw("ARRAY[1,2,3,4,5,6,7,8,9,10,11,12]::integer[]"),
      })
    );
  }

  await trx("bike_prices").insert({
    bike_id: bike.id,
    season_id: season.id,
    days_type: "1d",
    price_per_day: 500,
  });

  return {bike, userA, userB};
}

function buildBooking(bikeId, startDate, endDate) {
  return {
    selectedBikeId: bikeId,
    startDate,
    startTime: null,
    endDate,
    endTime: null,
    totalPrice: 1500,
    pricePerDay: 500,
    priceUnknown: false,
    helmets: 1,
    deliveryRequired: false,
    deliveryAddress: null,
    notes: "service check",
  };
}

async function expectServiceError(code, fn) {
  try {
    await fn();
  } catch (error) {
    assert.equal(error.code, code);
    return;
  }
  assert.fail(`Expected service error: ${code}`);
}

async function runChecks(trx) {
  const {bike, userA, userB} = await seed(trx);

  const pricing = await calculateBikePricing(trx, {
    bikeId: bike.id,
    startDate: "2030-01-10",
    endDate: "2030-01-12",
  });
  assert.equal(pricing.days, 3);
  assert.equal(pricing.daysType, "1d");
  assert.equal(pricing.totalPrice, 1500);
  assert.equal(pricing.priceUnknown, false);

  const firstDraft = await createDraftRental(trx, {
    user: userA,
    booking: buildBooking(bike.id, "2030-01-10", "2030-01-12"),
  });
  assert.equal(firstDraft.created, true);
  assert.equal(firstDraft.rental.status, RENTAL_STATUS.PROCESS);

  const duplicateDraft = await createDraftRental(trx, {
    user: userA,
    booking: buildBooking(bike.id, "2030-01-10", "2030-01-12"),
  });
  assert.equal(duplicateDraft.created, false);

  const processCount = await trx("rentals")
    .where({user_id: userA.id, bike_id: bike.id, status: RENTAL_STATUS.PROCESS})
    .count({count: "*"})
    .first();
  assert.equal(Number(processCount.count), 1);

  const confirmed = await confirmDraftRentals(trx, {userId: userA.id});
  assert.equal(confirmed.length, 1);
  assert.equal(confirmed[0].status, RENTAL_STATUS.PENDING);

  const doubleConfirm = await confirmDraftRentals(trx, {userId: userA.id});
  assert.equal(doubleConfirm.length, 0);

  const approved = await approveRental(trx, {rentalId: confirmed[0].id});
  assert.equal(approved.status, "approved");
  assert.equal(approved.rental.status, RENTAL_STATUS.APPROVED);

  const remindersAfterApprove = await trx("reminders")
    .where({rental_id: confirmed[0].id, sent: false})
    .count({count: "*"})
    .first();
  assert.equal(Number(remindersAfterApprove.count), 4);

  await expectServiceError("overlap_conflict", () =>
    createDraftRental(trx, {
      user: userB,
      booking: buildBooking(bike.id, "2030-01-11", "2030-01-13"),
    })
  );

  const userCancelled = await cancelRentalByUser(trx, {
    rentalId: confirmed[0].id,
    userId: userA.id,
  });
  assert.equal(userCancelled.status, "cancelled");
  assert.equal(userCancelled.rental.status, RENTAL_STATUS.CANCELLED_BY_CLIENT);

  const remindersAfterUserCancel = await trx("reminders")
    .where({rental_id: confirmed[0].id})
    .count({count: "*"})
    .first();
  assert.equal(Number(remindersAfterUserCancel.count), 0);

  const approveCancelled = await approveRental(trx, {rentalId: confirmed[0].id});
  assert.equal(approveCancelled.status, "status_changed");

  const secondDraft = await createDraftRental(trx, {
    user: userB,
    booking: buildBooking(bike.id, "2030-01-11", "2030-01-13"),
  });
  const secondConfirmed = await confirmDraftRentals(trx, {userId: userB.id});
  assert.equal(secondDraft.created, true);
  assert.equal(secondConfirmed.length, 1);

  await trx("reminders").insert({
    rental_id: secondConfirmed[0].id,
    type: "start_24h",
    send_at: new Date("2030-01-10T00:00:00.000Z"),
    sent: false,
  });

  const adminCancelled = await cancelRentalByAdmin(trx, {
    rentalId: secondConfirmed[0].id,
  });
  assert.equal(adminCancelled.status, "cancelled");
  assert.equal(adminCancelled.rental.status, RENTAL_STATUS.CANCELLED);

  const remindersAfterAdminCancel = await trx("reminders")
    .where({rental_id: secondConfirmed[0].id})
    .count({count: "*"})
    .first();
  assert.equal(Number(remindersAfterAdminCancel.count), 0);
}

async function main() {
  try {
    await db.transaction(async (trx) => {
      await runChecks(trx);
      throw ROLLBACK;
    });
  } catch (error) {
    if (error !== ROLLBACK) {
      throw error;
    }
  }

  console.log("Rental service checks passed.");
}

main()
  .catch((error) => {
    console.error(error);
    process.exitCode = 1;
  })
  .finally(() => db.destroy());
