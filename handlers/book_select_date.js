const showAvailableBikes = require("./book_show_available_bikes");
const db = require("../connect");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const selectedDate = data.split(":")[2];

  const booking = ensureBooking(ctx);
  booking.scenario = booking.scenario || "date_first";
  booking.step = booking.step || "select_date";

  const lang = getCtxLang(ctx);

  // Step 1 - pick start date
  if (!booking.startDate) {
    booking.startDate = selectedDate;
    booking.step = "select_end_date";
    booking.calendarYear = dayjs(selectedDate).year();
    booking.calendarMonth = dayjs(selectedDate).month() + 1;

    let blockedDays = [];
    if (booking.selectedBikeId) {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
    }

    return ctx.editMessageText(t(lang, "booking_choose_end_date"), {
      reply_markup: require("../utils/calendar").generateCalendarKeyboard(
        Number(selectedDate.split("-")[0]),
        Number(selectedDate.split("-")[1]),
        blockedDays,
        {
          lang,
          labels: getCalendarLabels(lang),
          weekdays: getWeekdays(lang),
        }
      ),
    });
  }

  // Step 2 - pick end date
  const start = dayjs(booking.startDate);
  const end = dayjs(selectedDate);

  if (end.isBefore(start)) {
    await ctx.answerCallbackQuery(t(lang, "booking_end_before_start"), {
      show_alert: true,
    });
    return;
  }

  booking.endDate = selectedDate;
  booking.step = "dates_selected";

  if (booking.selectedBikeId) {
    const blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);

    const selectedDates = [];
    let current = start;
    while (current.isBefore(end) || current.isSame(end, "day")) {
      selectedDates.push(current.format("YYYY-MM-DD"));
      current = current.add(1, "day");
    }

    const hasConflict = selectedDates.some((date) => blockedDays.includes(date));
    if (hasConflict) {
      try {
        await ctx.deleteMessage();
      } catch (e) {
        console.warn("Не удалось удалить сообщение:", e.message);
      }

      booking.startDate = null;
      booking.endDate = null;
      booking.step = "select_start_date";
      return ctx.reply(t(lang, "booking_range_conflict"), {
        reply_markup: require("../utils/calendar").generateCalendarKeyboard(
          dayjs().year(),
          dayjs().month() + 1,
          blockedDays,
          {
            lang,
            labels: getCalendarLabels(lang),
            weekdays: getWeekdays(lang),
          }
        ),
      });
    }
  }

  if (booking.startDate && booking.endDate && booking.selectedBikeId) {
    const bikeHandler = require("./book_select_bike");
    ctx.callbackQuery.data = `book:select_bike:${booking.selectedBikeId}`;
    return bikeHandler(ctx);
  }

  return showAvailableBikes(ctx);
};
