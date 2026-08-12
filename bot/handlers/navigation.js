const dayjs = require("dayjs");
const {createEmptyBooking, ensureBooking} = require("../services/bookingService");
const {
  generateCalendarKeyboard,
  getCalendarBackAction,
  getCalendarDisplayRange,
} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const db = require("../connect");
const {getCtxLang, getCalendarLabels, getWeekdays, t} = require("../utils/i18n");
const {botScreenRenderer} = require("../services/botScreenRenderer");

async function showStartDateCalendar(ctx, booking, lang, options = {}) {
  const displayDate = booking.startDate ? dayjs(booking.startDate) : dayjs();
  booking.startDate = null;
  booking.startTime = null;
  booking.endDate = null;
  booking.endTime = null;
  booking.totalPrice = null;
  booking.pricePerDay = null;
  booking.priceUnknown = false;
  booking.step = "select_start_date";
  booking.calendarMonth = displayDate.month() + 1;
  booking.calendarYear = displayDate.year();

  const blockedDays = booking.selectedBikeId
    ? await getBusyDatesForBike(
        booking.selectedBikeId,
        db,
        getCalendarDisplayRange(booking.calendarYear, booking.calendarMonth)
      )
    : [];
  const keyboard = generateCalendarKeyboard(
    booking.calendarYear,
    booking.calendarMonth,
    blockedDays,
    {
      lang,
      labels: getCalendarLabels(lang),
      weekdays: getWeekdays(lang),
      disablePast: true,
      backAction: getCalendarBackAction(booking),
    }
  );

  return (options.renderer || botScreenRenderer).renderText(ctx, {
    screen: "booking_start_date",
    text: t(lang, "booking_choose_start_date"),
    replyMarkup: keyboard,
    returnContext: {
      scenario: booking.scenario,
      categoryId: booking.categoryId || null,
      selectedBikeId: booking.selectedBikeId || null,
    },
    navigationMode: options.navigationMode || "back",
  });
}

async function showEndDateCalendar(ctx, booking, lang, options = {}) {
  if (!booking.startDate) {
    return showStartDateCalendar(ctx, booking, lang, options);
  }

  const displayDate = booking.endDate
    ? dayjs(booking.endDate)
    : dayjs(booking.startDate);
  booking.endDate = null;
  booking.endTime = null;
  booking.totalPrice = null;
  booking.pricePerDay = null;
  booking.priceUnknown = false;
  booking.step = "select_end_date";
  booking.calendarMonth = displayDate.month() + 1;
  booking.calendarYear = displayDate.year();

  const blockedDays = booking.selectedBikeId
    ? await getBusyDatesForBike(
        booking.selectedBikeId,
        db,
        getCalendarDisplayRange(booking.calendarYear, booking.calendarMonth)
      )
    : [];
  const keyboard = generateCalendarKeyboard(
    booking.calendarYear,
    booking.calendarMonth,
    blockedDays,
    {
      lang,
      labels: getCalendarLabels(lang),
      weekdays: getWeekdays(lang),
      minDate: booking.startDate,
      selectedDate: booking.startDate,
      backAction: getCalendarBackAction(booking),
    }
  );

  return (options.renderer || botScreenRenderer).renderText(ctx, {
    screen: "booking_end_date",
    text: t(lang, "booking_choose_end_date"),
    replyMarkup: keyboard,
    returnContext: {
      scenario: booking.scenario,
      categoryId: booking.categoryId || null,
      selectedBikeId: booking.selectedBikeId || null,
      startDate: booking.startDate,
    },
    navigationMode: options.navigationMode || "back",
  });
}

// Universal navigation handlers for back/home and calendar navigation.
module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data;
  const lang = getCtxLang(ctx);

  if (action === "book:restart") {
    ctx.session.booking = createEmptyBooking();
    return require("../commands/book")(ctx);
  }

  if (action === "home") {
    return require("./main_menu").showMainMenu(ctx, lang);
  }

  if (action === "book:calendar_back_start") {
    return showStartDateCalendar(ctx, ensureBooking(ctx), lang);
  }

  if (action === "book:calendar_back_end") {
    return showEndDateCalendar(ctx, ensureBooking(ctx), lang);
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
      blockedDays = await getBusyDatesForBike(
        booking.selectedBikeId,
        db,
        getCalendarDisplayRange(booking.calendarYear, booking.calendarMonth)
      );
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
        backAction: getCalendarBackAction(booking),
      }
    );

    const selectingEnd = booking.step === "select_end_date";
    return botScreenRenderer.renderText(ctx, {
      screen: selectingEnd ? "booking_end_date" : "booking_start_date",
      text: t(
        lang,
        selectingEnd ? "booking_choose_end_date" : "booking_choose_start_date"
      ),
      replyMarkup: keyboard,
      returnContext: {
        scenario: booking.scenario,
        categoryId: booking.categoryId || null,
        selectedBikeId: booking.selectedBikeId || null,
      },
      navigationMode: "replace",
    });
  }
};

module.exports.showStartDateCalendar = showStartDateCalendar;
module.exports.showEndDateCalendar = showEndDateCalendar;
