const dayjs = require("dayjs");
const {BLOCKING_RENTAL_STATUSES} = require("./rentalStatus");

/**
 * Adds overlap conditions for rentals with datetime (start_at/end_at) and legacy date (start_date/end_date).
 */
function applyOverlapCondition(qb, startAt, endAt, startDate, endDate) {
  qb.where(function () {
    if (startAt && endAt) {
      this.where(function () {
        this.whereBetween("start_at", [startAt, endAt])
          .orWhereBetween("end_at", [startAt, endAt])
          .orWhere(function () {
            this.where("start_at", "<=", startAt).andWhere("end_at", ">=", endAt);
          });
      }).orWhere(function () {
        this.whereNull("start_at")
          .whereNull("end_at")
          .andWhere(function () {
            this.whereBetween("start_date", [startDate, endDate])
              .orWhereBetween("end_date", [startDate, endDate])
              .orWhere(function () {
                this.where("start_date", "<=", startDate).andWhere("end_date", ">=", endDate);
              });
          });
      });
      return;
    }

    this.whereBetween("start_date", [startDate, endDate])
      .orWhereBetween("end_date", [startDate, endDate])
      .orWhere(function () {
        this.where("start_date", "<=", startDate).andWhere("end_date", ">=", endDate);
      });
  });
}

function toDateStr(date) {
  return dayjs(date).format("YYYY-MM-DD");
}

async function hasOverlap(db, bikeId, startAt, endAt, startDate, endDate, excludeRentalId) {
  const query = db("rentals")
    .where("bike_id", bikeId)
    .whereIn("status", BLOCKING_RENTAL_STATUSES);

  if (excludeRentalId) {
    query.andWhere("id", "!=", excludeRentalId);
  }

  query.andWhere((qb) => applyOverlapCondition(qb, startAt, endAt, startDate, endDate));

  const row = await query.first();
  return Boolean(row);
}

module.exports = {applyOverlapCondition, toDateStr, hasOverlap};
