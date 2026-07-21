const dayjs = require("dayjs");
const {createEmptyBooking, ensureBooking} = require("../services/bookingService");
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const db = require("../connect");
const {getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");

// Universal navigation handlers for back/home and calendar navigation.
module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data;
  const lang = getCtxLang(ctx);

  if (action === "book:restart") {
    ctx.session.booking = createEmptyBooking();
    await ctx.answerCallbackQuery();
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return require("../commands/book")(ctx);
  }

  if (action === "home") {
    ctx.session.booking = null;
    await ctx.answerCallbackQuery();
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return require("../commands/start")(ctx);
  }

  if (action === "book:calendar_prev" || action === "book:calendar_next") {
    const booking = ensureBooking(ctx);
    const currentMonth = booking.calendarMonth || dayjs().month() + 1;
    const currentYear = booking.calendarYear || dayjs().year();

    const direction = action.endsWith("prev") ? -1 : 1;
    const newDate = dayjs(
      `${currentYear}-${String(currentMonth).padStart(2, "0")}-01`
    ).add(direction, "month");

    booking.calendarMonth = newDate.month() + 1;
    booking.calendarYear = newDate.year();

    let blockedDays = [];
    if (booking.selectedBikeId) {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
    }

    const keyboard = generateCalendarKeyboard(
      booking.calendarYear,
      booking.calendarMonth,
      blockedDays,
      {
        lang,
        labels: getCalendarLabels(lang),
        weekdays: getWeekdays(lang),
        minDate: booking.step === "select_end_date" ? booking.startDate : null,
        disablePast: booking.step !== "select_end_date",
        selectedDate:
          booking.step === "select_end_date" ? booking.startDate : null,
      }
    );

    await ctx.editMessageReplyMarkup({reply_markup: keyboard});
    return ctx.answerCallbackQuery();
  }
};
