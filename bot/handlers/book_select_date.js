const dayjs = require("dayjs");
const showAvailableBikes = require("./book_show_available_bikes");
const db = require("../connect");
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const selectedDate = data.split(":")[2];

  const booking = ensureBooking(ctx);
  booking.scenario = booking.scenario || "date_first";
  booking.step = booking.step || "select_date";

  const lang = getCtxLang(ctx);

  const today = dayjs().startOf("day");
  const picked = dayjs(selectedDate);
  if (picked.isBefore(today, "day")) {
    await ctx.answerCallbackQuery({
      text: t(lang, "booking_date_in_past"),
      show_alert: true,
    });
    return;
  }

  // Step 1: pick start date
  if (!booking.startDate || booking.step === "select_start_date") {
    booking.startDate = selectedDate;
    booking.startTime = null;
    booking.endDate = null;
    booking.endTime = null;
    booking.step = "select_end_date";
    booking.calendarYear = dayjs(selectedDate).year();
    booking.calendarMonth = dayjs(selectedDate).month() + 1;

    let blockedDays = [];
    if (booking.selectedBikeId) {
      const rangeStart = dayjs(selectedDate).startOf("month").startOf("week");
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db, {
        startDate: rangeStart.format("YYYY-MM-DD"),
        endDate: rangeStart.add(41, "day").format("YYYY-MM-DD"),
      });
    }

    return ctx.editMessageText(t(lang, "booking_choose_end_date"), {
      reply_markup: generateCalendarKeyboard(
        dayjs(selectedDate).year(),
        dayjs(selectedDate).month() + 1,
        blockedDays,
        {
          lang,
          labels: getCalendarLabels(lang),
          weekdays: getWeekdays(lang),
          minDate: booking.startDate,
          selectedDate: booking.startDate,
        }
      ),
    });
  }

  // Step 2: pick end date
  const start = dayjs(booking.startDate);
  const end = dayjs(selectedDate);

  if (end.isBefore(start)) {
    await ctx.answerCallbackQuery({
      text: t(lang, "booking_end_before_start"),
      show_alert: true,
    });
    return;
  }

  booking.endDate = selectedDate;
  booking.endTime = null;
  booking.step = "dates_selected";

  return showAvailableBikes(ctx);
};
