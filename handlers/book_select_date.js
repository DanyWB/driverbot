const dayjs = require("dayjs");
const showAvailableBikes = require("./book_show_available_bikes");
const db = require("../connect");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const selectedDate = data.split(":")[2];

  const booking = ensureBooking(ctx);
  booking.scenario = booking.scenario || "date_first";
  booking.step = booking.step || "select_date";

  const lang = getCtxLang(ctx);

  // Step 1: pick start date
  if (!booking.startDate) {
    booking.startDate = selectedDate;
    booking.startTime = null;
    booking.step = "select_start_time";
    return ctx.editMessageText(t(lang, "booking_choose_start_time"), {
      reply_markup: require("./book_select_time").getTimeKeyboard("start"),
    });
  }

  // If user changes start date before time chosen
  if (booking.startDate && !booking.startTime) {
    booking.startDate = selectedDate;
    booking.step = "select_start_time";
    return ctx.editMessageText(t(lang, "booking_choose_start_time"), {
      reply_markup: require("./book_select_time").getTimeKeyboard("start"),
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
  booking.step = "select_end_time";
  booking.endTime = null;

  return ctx.editMessageText(t(lang, "booking_choose_end_time"), {
    reply_markup: require("./book_select_time").getTimeKeyboard("end"),
  });
};
