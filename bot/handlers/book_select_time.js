const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");
const {getTimeSlots, makeDateTime} = require("../utils/timeSlots");
const {getTimeKeyboard} = require("../utils/timeKeyboard");
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getCalendarLabels, getWeekdays, getCtxLang, t} = require("../utils/i18n");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const db = require("../connect");
const showAvailableBikes = require("./book_show_available_bikes");
const {isLaravelMode} = require("../config/runtime");

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
    const rangeStart = startAt.startOf("month").startOf("week");
    blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db, {
      startDate: rangeStart.format("YYYY-MM-DD"),
      endDate: rangeStart.add(41, "day").format("YYYY-MM-DD"),
    });
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
        minDate: booking.startDate,
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
  if (booking.selectedBikeId && !isLaravelMode()) {
    const rangeStart = dayjs(booking.startDate).startOf("month").startOf("week");
    const blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db, {
      startDate: rangeStart.format("YYYY-MM-DD"),
      endDate: dayjs(booking.endDate).endOf("month").endOf("week").format("YYYY-MM-DD"),
    });
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
        reply_markup: generateCalendarKeyboard(
          dayjs().year(),
          dayjs().month() + 1,
          blockedDays,
          {
            lang,
            labels: getCalendarLabels(lang),
            weekdays: getWeekdays(lang),
            disablePast: true,
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
}

async function handleStartTimeOptional(ctx, booking, lang) {
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

  if (booking.endDate && booking.endTime) {
    const endAt = makeDateTime(booking.endDate, booking.endTime);
    if (endAt && endAt.isValid() && endAt.isBefore(startAt)) {
      booking.startTime = null;
      return ctx.answerCallbackQuery({
        text: t(lang, "booking_end_before_start"),
        show_alert: true,
      });
    }
  }

  const {formatOptionsText, getOptionsKeyboard, persistProcessOptions} = require("./book_options");
  booking.timeSource = null;
  if (ctx.session.optionsScope === "process") {
    await persistProcessOptions(ctx, booking);
  }
  return ctx.editMessageText(formatOptionsText(booking, lang), {
    reply_markup: getOptionsKeyboard(lang),
  });
}

async function handleEndTimeOptional(ctx, booking, lang) {
  const endAt = makeDateTime(booking.endDate, booking.endTime);
  if (!endAt || !endAt.isValid()) {
    booking.endTime = null;
    return ctx.answerCallbackQuery({
      text: t(lang, "booking_time_invalid"),
      show_alert: true,
    });
  }

  if (booking.startDate && booking.startTime) {
    const startAt = makeDateTime(booking.startDate, booking.startTime);
    if (startAt && startAt.isValid() && endAt.isBefore(startAt)) {
      booking.endTime = null;
      return ctx.answerCallbackQuery({
        text: t(lang, "booking_end_before_start"),
        show_alert: true,
      });
    }
  }

  const {formatOptionsText, getOptionsKeyboard, persistProcessOptions} = require("./book_options");
  booking.timeSource = null;
  if (ctx.session.optionsScope === "process") {
    await persistProcessOptions(ctx, booking);
  }
  return ctx.editMessageText(formatOptionsText(booking, lang), {
    reply_markup: getOptionsKeyboard(lang),
  });
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

  const isOptions = booking.timeSource === "options";

  if (kind === "start") {
    booking.startTime = time;
    return isOptions
      ? handleStartTimeOptional(ctx, booking, lang)
      : handleStartTime(ctx, booking, lang);
  }

  if (kind === "end") {
    booking.endTime = time;
    return isOptions
      ? handleEndTimeOptional(ctx, booking, lang)
      : handleEndTime(ctx, booking, lang);
  }
};

module.exports.getTimeKeyboard = getTimeKeyboard;
