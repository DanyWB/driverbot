const {generateCalendarKeyboard} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const db = require("../connect");

module.exports = async (ctx) => {
  const booking = ctx.session.booking || {};
  const today = new Date();
  const currentMonth = booking.calendarMonth || today.getMonth() + 1;
  const currentYear = booking.calendarYear || today.getFullYear();

  let month = currentMonth + 1;
  let year = currentYear;
  if (month > 12) {
    month = 1;
    year += 1;
  }

  // Сохраняем новые значения в сессии
  booking.calendarMonth = month;
  booking.calendarYear = year;

  let blockedDays = [];
  if (booking.selectedBikeId) {
    blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
  }

  return ctx.editMessageReplyMarkup({
    reply_markup: generateCalendarKeyboard(year, month, blockedDays),
  });
};
