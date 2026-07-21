const dayjs = require("dayjs");

function generateBookingId() {
  const datePart = dayjs().format("YYYYMMDD");
  const randomPart = Math.random().toString(36).substring(2, 6).toUpperCase();
  return `DP-${datePart}-${randomPart}`;
}

module.exports = {generateBookingId};
