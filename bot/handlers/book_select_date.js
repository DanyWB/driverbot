const dayjs = require("dayjs");
const showAvailableBikes = require("./book_show_available_bikes");
const db = require("../connect");
const {
  generateCalendarKeyboard,
  getCalendarBackAction,
  getCalendarDisplayRange,
  isIsoCalendarDay,
} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const selectedDate = data.split(":")[2];

  const booking = ensureBooking(ctx);
  booking.scenario = booking.scenario || "date_first";
  booking.step = booking.step || "select_date";

  const lang = getCtxLang(ctx);

  if (!isIsoCalendarDay(selectedDate)) {
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_date_invalid"),
      show_alert: true,
    });
  }

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
      blockedDays = await getBusyDatesForBike(
        booking.selectedBikeId,
        db,
        getCalendarDisplayRange(
          booking.calendarYear,
          booking.calendarMonth
        )
      );
    }

    return botScreenRenderer.renderText(ctx, {
      screen: "booking_end_date",
      text: t(lang, "booking_choose_end_date"),
      replyMarkup: generateCalendarKeyboard(
        dayjs(selectedDate).year(),
        dayjs(selectedDate).month() + 1,
        blockedDays,
        {
          lang,
          labels: getCalendarLabels(lang),
          weekdays: getWeekdays(lang),
          minDate: booking.startDate,
          selectedDate: booking.startDate,
          backAction: getCalendarBackAction(booking),
        }
      ),
      returnContext: {
        scenario: booking.scenario,
        categoryId: booking.categoryId || null,
        selectedBikeId: booking.selectedBikeId || null,
        startDate: booking.startDate,
      },
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
