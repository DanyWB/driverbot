const db = require("../connect");
const {
  BLOCKING_RENTAL_STATUSES,
  KNOWN_RENTAL_STATUSES,
  RENTAL_STATUS,
} = require("../utils/rentalStatus");
const {KNOWN_VEHICLE_TYPES} = require("../utils/vehicleTypes");

const REQUIRED_PRICE_ROWS_PER_BIKE = 15;

function toNumber(value) {
  return Number(value || 0);
}

function printRows(title, rows, formatRow) {
  if (!rows.length) {
    console.log(`[ok] ${title}`);
    return false;
  }

  console.log(`[fail] ${title}`);
  rows.forEach((row) => console.log(`  - ${formatRow(row)}`));
  return true;
}

async function main() {
  let hasFailures = false;

  const statusRows = await db("rentals").select("status").count({ count: "*" }).groupBy("status");
  const unknownStatuses = statusRows
    .filter((row) => !KNOWN_RENTAL_STATUSES.includes(row.status))
    .map((row) => ({ status: row.status, count: toNumber(row.count) }));

  hasFailures =
    printRows(
      "Unknown rental statuses",
      unknownStatuses,
      (row) => `${row.status || "<null>"} (${row.count})`,
    ) || hasFailures;

  const duplicatePublicIds = await db("rentals")
    .select("booking_public_id")
    .count({ count: "*" })
    .whereNotNull("booking_public_id")
    .groupBy("booking_public_id")
    .havingRaw("COUNT(*) > 1");

  hasFailures =
    printRows(
      "Duplicate rental booking_public_id values",
      duplicatePublicIds.map((row) => ({
        booking_public_id: row.booking_public_id,
        count: toNumber(row.count),
      })),
      (row) => `${row.booking_public_id} (${row.count})`,
    ) || hasFailures;

  const duplicatePrices = await db("bike_prices")
    .select("bike_id", "season_id", "days_type")
    .count({ count: "*" })
    .groupBy("bike_id", "season_id", "days_type")
    .havingRaw("COUNT(*) > 1");

  hasFailures =
    printRows(
      "Duplicate vehicle price keys",
      duplicatePrices.map((row) => ({
        bike_id: row.bike_id,
        season_id: row.season_id,
        days_type: row.days_type,
        count: toNumber(row.count),
      })),
      (row) =>
        `bike_id=${row.bike_id}, season_id=${row.season_id}, days_type=${row.days_type || "<null>"} (${row.count})`,
    ) || hasFailures;

  const invalidVehicleTypes = await db("bikes")
    .select("id", "name", "vehicle_type")
    .where((builder) => {
      builder.whereNull("vehicle_type").orWhereNotIn("vehicle_type", KNOWN_VEHICLE_TYPES);
    })
    .orderBy("id", "asc");

  hasFailures =
    printRows(
      "Invalid vehicle types",
      invalidVehicleTypes,
      (row) => `vehicle_id=${row.id}, name="${row.name}", vehicle_type=${row.vehicle_type || "<null>"}`,
    ) || hasFailures;

  const invalidPriceRows = await db("bike_prices")
    .select("id", "bike_id", "season_id", "days_type", "price_per_day")
    .where((builder) => {
      builder
        .whereNull("bike_id")
        .orWhereNull("season_id")
        .orWhereNull("days_type")
        .orWhereNull("price_per_day")
        .orWhere("price_per_day", "<", 0);
    })
    .orderBy("id", "asc");

  hasFailures =
    printRows(
      "Invalid vehicle price rows",
      invalidPriceRows,
      (row) =>
        `price_id=${row.id}, bike_id=${row.bike_id || "<null>"}, season_id=${row.season_id || "<null>"}, days_type=${row.days_type || "<null>"}, price=${row.price_per_day || "<null>"}`,
    ) || hasFailures;

  const incompleteActiveBikePrices = await db("bikes")
    .leftJoin("bike_prices", "bikes.id", "bike_prices.bike_id")
    .select("bikes.id", "bikes.name", "bikes.vehicle_type")
    .count({ prices: "bike_prices.id" })
    .where("bikes.is_active", true)
    .groupBy("bikes.id", "bikes.name", "bikes.vehicle_type")
    .havingRaw("COUNT(bike_prices.id) < ?", [REQUIRED_PRICE_ROWS_PER_BIKE]);

  hasFailures =
    printRows(
      "Active vehicles with incomplete price matrix",
      incompleteActiveBikePrices.map((row) => ({
        id: row.id,
        name: row.name,
        vehicle_type: row.vehicle_type,
        prices: toNumber(row.prices),
      })),
      (row) => `vehicle_id=${row.id}, type=${row.vehicle_type}, name="${row.name}", prices=${row.prices}/${REQUIRED_PRICE_ROWS_PER_BIKE}`,
    ) || hasFailures;

  const activeRentalsWithoutPublicId = await db("rentals")
    .select("id", "status")
    .whereIn("status", BLOCKING_RENTAL_STATUSES)
    .whereNull("booking_public_id")
    .orderBy("id", "asc");

  hasFailures =
    printRows(
      "Blocking rentals without booking_public_id",
      activeRentalsWithoutPublicId,
      (row) => `rental_id=${row.id}, status=${row.status}`,
    ) || hasFailures;

  const invalidDateRanges = await db("rentals")
    .select("id", "start_date", "end_date")
    .whereRaw("start_date > end_date")
    .orderBy("id", "asc");

  hasFailures =
    printRows(
      "Invalid rental date ranges",
      invalidDateRanges,
      (row) => `rental_id=${row.id}, start_date=${row.start_date}, end_date=${row.end_date}`,
    ) || hasFailures;

  const invalidTimeRanges = await db("rentals")
    .select("id", "start_at", "end_at")
    .whereRaw(`
      (start_at IS NULL AND end_at IS NOT NULL)
      OR (start_at IS NOT NULL AND end_at IS NULL)
      OR (start_at IS NOT NULL AND end_at IS NOT NULL AND start_at >= end_at)
    `)
    .orderBy("id", "asc");

  hasFailures =
    printRows(
      "Invalid rental time ranges",
      invalidTimeRanges,
      (row) => `rental_id=${row.id}, start_at=${row.start_at || "<null>"}, end_at=${row.end_at || "<null>"}`,
    ) || hasFailures;

  const pendingRemindersForInactiveRentals = await db("reminders")
    .join("rentals", "reminders.rental_id", "rentals.id")
    .select("reminders.id", "reminders.rental_id", "rentals.status")
    .where("reminders.sent", false)
    .whereNotIn("rentals.status", [
      RENTAL_STATUS.APPROVED,
      RENTAL_STATUS.ACTIVE,
      RENTAL_STATUS.READY,
    ])
    .orderBy("reminders.id", "asc");

  hasFailures =
    printRows(
      "Unsent reminders attached to inactive rentals",
      pendingRemindersForInactiveRentals,
      (row) => `reminder_id=${row.id}, rental_id=${row.rental_id}, status=${row.status}`,
    ) || hasFailures;

  if (hasFailures) {
    console.error("Data checks failed.");
    process.exitCode = 1;
    return;
  }

  console.log("Data checks passed.");
}

main()
  .catch((error) => {
    console.error(error);
    process.exitCode = 1;
  })
  .finally(() => db.destroy());
