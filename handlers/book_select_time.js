const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");
const {getTimeSlots, makeDateTime} = require("../utils/timeSlots");
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getCalendarLabels, getWeekdays, getCtxLang, t} = require("../utils/i18n");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const db = require("../connect");
const showAvailableBikes = require("./book_show_available_bikes");

function getTimeKeyboard(kind) {
  const slots = getTimeSlots();
  const rows = [];
  for (let i = 0; i < slots.length; i += 3) {
    rows.push(
      slots.slice(i, i + 3).map((time) => ({
        text: time,
        callback_data: `book:time:${kind}:${time}`,
      }))
    );
  }
  return {inline_keyboard: rows};
}

async function handleStartTime(ctx, booking, lang) {
  const startAt = makeDateTime(booking.startDate, booking.startTime);
  if (!startAt || !startAt.isValid()) {
    booking.startTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  const minStart = dayjs().add(1, "hour");
  if (startAt.isBefore(minStart)) {
    booking.startTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_start_in_past"),
      show_alert: true,
    });
  }

  booking.calendarYear = startAt.year();
  booking.calendarMonth = startAt.month() + 1;
  booking.step = "select_end_date";

  let blockedDays = [];
  if (booking.selectedBikeId) {
    blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
  }

  return ctx.editMessageText(t(lang, "booking_choose_end_date"), {
    reply_markup: generateCalendarKeyboard(
      booking.calendarYear,
      booking.calendarMonth,
      blockedDays,
      {
        lang,
        labels: getCalendarLabels(lang),
        weekdays: getWeekdays(lang),
      }
    ),
  });
}

async function handleEndTime(ctx, booking, lang) {
  const startAt = makeDateTime(booking.startDate, booking.startTime);
  const endAt = makeDateTime(booking.endDate, booking.endTime);
  if (!endAt || !endAt.isValid()) {
    booking.endTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  if (!startAt || !startAt.isValid()) {
    booking.endTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  if (endAt.isBefore(startAt)) {
    booking.endTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_end_before_start"),
      show_alert: true,
    });
  }

  const minDuration = startAt.add(1, "hour");
  if (endAt.isBefore(minDuration)) {
    booking.endTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_min_duration"),
      show_alert: true,
    });
  }

  booking.step = "dates_selected";

  // Check blocked days for selected bike (date-level)
  if (booking.selectedBikeId) {
    const blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
    const selectedDates = [];
    let current = dayjs(booking.startDate);
    const end = dayjs(booking.endDate);
    while (current.isBefore(end) || current.isSame(end, "day")) {
      selectedDates.push(current.format("YYYY-MM-DD"));
      current = current.add(1, "day");
    }
    const hasConflict = selectedDates.some((d) => blockedDays.includes(d));
    if (hasConflict) {
      booking.startDate = null;
      booking.startTime = null;
      booking.endDate = null;
      booking.endTime = null;
      booking.step = "select_start_date";
      return ctx.reply(t(lang, "booking_range_conflict"), {
        reply_markup: generateCalendarKeyboard(dayjs().year(), dayjs().month() + 1, blockedDays, {
          lang,
          labels: getCalendarLabels(lang),
          weekdays: getWeekdays(lang),
        }),
      });
    }
  }

  if (booking.startDate && booking.endDate && booking.selectedBikeId) {
    const bikeHandler = require("./book_select_bike");
    ctx.callbackQuery.data = `book:select_bike:${booking.selectedBikeId}`;
    return bikeHandler(ctx);
  }

  return showAvailableBikes(ctx);
}

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":"); // ["book","time","start","09","00"]
  const kind = parts[2];
  const time = parts.slice(3).join(":"); // "09:00"
  const lang = getCtxLang(ctx);

  const booking = ensureBooking(ctx);
  if (!booking.startDate) {
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_dates_not_selected"),
      show_alert: true,
    });
  }

  if (kind === "end" && !booking.endDate) {
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_dates_not_selected"),
      show_alert: true,
    });
  }

  if (!["start", "end"].includes(kind)) {
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  if (!getTimeSlots().includes(time)) {
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  if (kind === "start") {
    booking.startTime = time;
    return handleStartTime(ctx, booking, lang);
  }

  if (kind === "end") {
    booking.endTime = time;
    return handleEndTime(ctx, booking, lang);
  }
};

module.exports.getTimeKeyboard = getTimeKeyboard;
