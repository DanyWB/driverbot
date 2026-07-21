const dayjs = require("dayjs");
const {makeDateTime} = require("../utils/timeSlots");
const {applyOverlapCondition} = require("../utils/overlap");
const {roundTotal} = require("../utils/pricingProfiles");
const {BLOCKING_RENTAL_STATUSES} = require("../utils/rentalStatus");
const {
  DEFAULT_VEHICLE_TYPE,
  KNOWN_VEHICLE_TYPES,
  normalizeVehicleType,
} = require("../utils/vehicleTypes");

function normalizeVehicle(row) {
  if (!row) return row;
  return {
    ...row,
    vehicle_type: normalizeVehicleType(row.vehicle_type),
  };
}

function getBookingDateTimes({startDate, endDate, startTime = null, endTime = null}) {
  const startAt = makeDateTime(startDate, startTime);
  const endAt = makeDateTime(endDate, endTime);

  return {
    startAt,
    endAt,
    startAtIso: startAt ? startAt.toISOString() : null,
    endAtIso: endAt ? endAt.toISOString() : null,
  };
}

async function getVehicleById(client, vehicleId) {
  const row = await client("bikes").where({id: vehicleId}).first();
  return normalizeVehicle(row);
}

async function getActiveVehicleById(client, vehicleId) {
  const row = await client("bikes")
    .where({id: vehicleId, is_active: true})
    .first();
  return normalizeVehicle(row);
}

async function findBusyVehicleIds(
  client,
  {startDate, endDate, startTime = null, endTime = null}
) {
  const {startAtIso, endAtIso} = getBookingDateTimes({
    startDate,
    endDate,
    startTime,
    endTime,
  });

  const rows = await client("rentals")
    .select("bike_id")
    .whereIn("status", BLOCKING_RENTAL_STATUSES)
    .andWhere((builder) => {
      applyOverlapCondition(builder, startAtIso, endAtIso, startDate, endDate);
    });

  return [...new Set(rows.map((row) => row.bike_id).filter(Boolean))];
}

async function listActiveVehicles(
  client,
  {categoryId = null, vehicleType = null, excludeIds = [], columns = null} = {}
) {
  const selectedColumns =
    columns && columns.length
      ? columns
      : [
          "id",
          "name",
          "category_id",
          "emoji",
          "vehicle_type",
          "inventory_code",
          "sort_order",
          "is_active",
        ];

  let query = client("bikes").select(selectedColumns).where({is_active: true});

  if (categoryId) {
    query = query.andWhere({category_id: categoryId});
  }

  if (vehicleType) {
    query = query.andWhere({vehicle_type: normalizeVehicleType(vehicleType)});
  }

  if (excludeIds.length) {
    query = query.whereNotIn("id", excludeIds);
  }

  const rows = await query
    .orderBy("vehicle_type")
    .orderBy("category_id")
    .orderBy("sort_order")
    .orderBy("name");

  return rows.map(normalizeVehicle);
}

async function listAvailableVehicles(
  client,
  {startDate, endDate, startTime = null, endTime = null, categoryId = null, vehicleType = null}
) {
  const busyIds = await findBusyVehicleIds(client, {
    startDate,
    endDate,
    startTime,
    endTime,
  });

  return listActiveVehicles(client, {
    categoryId,
    vehicleType,
    excludeIds: busyIds,
  });
}

function getDaysType(start, end, days) {
  if (start.date() === end.date() && end.diff(start, "month") === 1) {
    return "month";
  }
  if (days >= 1 && days <= 6) {
    return "1d";
  }
  if (days >= 7 && days <= 13) {
    return "7d";
  }
  if (days >= 14 && days <= 20) {
    return "14d";
  }
  if (days >= 21 && days <= 29) {
    return "21d";
  }
  return "month";
}

async function calculateVehiclePricing(
  client,
  {vehicleId, startDate, endDate, startTime = null, endTime = null}
) {
  const startAt = makeDateTime(startDate, startTime);
  const endAt = makeDateTime(endDate, endTime);
  const start = startAt ? dayjs(startAt) : dayjs(startDate);
  const end = endAt ? dayjs(endAt) : dayjs(endDate);

  const month = start.month() + 1;
  const seasons = await client("seasons").select("id", "months");
  const matchingSeason = seasons.find((season) => season.months.includes(month));
  if (!matchingSeason) {
    const error = new Error("season_not_found");
    error.code = "season_not_found";
    throw error;
  }

  const days = end.diff(start, "day") + 1;
  const daysType = getDaysType(start, end, days);
  const priceRow = await client("bike_prices")
    .where({bike_id: vehicleId, season_id: matchingSeason.id, days_type: daysType})
    .first();

  const pricePerDay = priceRow ? Number(priceRow.price_per_day) : null;
  const rawTotal = priceRow ? pricePerDay * days : 0;
  const totalPrice = priceRow ? roundTotal(rawTotal, days) : 0;

  return {
    seasonId: matchingSeason.id,
    days,
    daysType,
    priceRow,
    pricePerDay,
    totalPrice,
    priceUnknown: !priceRow,
  };
}

function buildVehicleInsertData(data) {
  return {
    name: data.name,
    category_id: data.category_id || null,
    description: data.description || null,
    emoji: data.emoji || null,
    vehicle_type: normalizeVehicleType(data.vehicle_type || DEFAULT_VEHICLE_TYPE),
    inventory_code: data.inventory_code || null,
    sort_order: Number.isFinite(Number(data.sort_order)) ? Number(data.sort_order) : 0,
    is_active: data.is_active !== false,
    pricing_profile: data.pricing_profile || null,
  };
}

async function createVehicle(client, data) {
  const rows = await client("bikes").insert(buildVehicleInsertData(data)).returning("*");
  return normalizeVehicle(rows[0]);
}

async function replaceVehiclePrices(client, vehicleId, priceRows) {
  await client("bike_prices").where({bike_id: vehicleId}).del();

  if (!priceRows?.length) {
    return;
  }

  const payload = priceRows.map((row) => ({
    bike_id: vehicleId,
    season_id: row.season_id,
    days_type: row.days_type,
    price_per_day: row.price_per_day,
  }));

  await client("bike_prices").insert(payload);
}

module.exports = {
  KNOWN_VEHICLE_TYPES,
  calculateVehiclePricing,
  createVehicle,
  findBusyVehicleIds,
  getActiveVehicleById,
  getBookingDateTimes,
  getDaysType,
  getVehicleById,
  listActiveVehicles,
  listAvailableVehicles,
  normalizeVehicle,
  replaceVehiclePrices,
};
