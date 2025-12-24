const dayjs = require("dayjs");

const DEFAULT_SLOTS = [
  "09:00",
  "10:00",
  "11:00",
  "12:00",
  "13:00",
  "14:00",
  "15:00",
  "16:00",
  "17:00",
  "18:00",
  "19:00",
  "20:00",
];

function getTimeSlots() {
  return DEFAULT_SLOTS;
}

function makeDateTime(dateStr, timeStr) {
  if (!dateStr || !timeStr) return null;
  const iso = `${dateStr}T${timeStr}:00`;
  const dt = dayjs(iso);
  return dt.isValid() ? dt : null;
}

module.exports = {getTimeSlots, makeDateTime};
