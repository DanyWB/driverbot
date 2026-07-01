const dayjs = require("dayjs");
const isSameOrBefore = require("dayjs/plugin/isSameOrBefore");
const {BLOCKING_RENTAL_STATUSES} = require("./rentalStatus");
dayjs.extend(isSameOrBefore);
async function getBusyDatesForBike(bikeId, db) {
  const rentals = await db("rentals")
    .where("bike_id", bikeId)
    .whereIn("status", BLOCKING_RENTAL_STATUSES);

  const blockedDays = new Set();

  for (const rental of rentals) {
    let current = dayjs(rental.start_date);
    const end = dayjs(rental.end_date);
    while (current.isBefore(end) || current.isSame(end, "day")) {
      blockedDays.add(current.format("YYYY-MM-DD"));
      current = current.add(1, "day");
    }
  }

  return Array.from(blockedDays);
}
module.exports = {getBusyDatesForBike};
