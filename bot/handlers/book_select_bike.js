const db = require("../connect");
const dayjs = require("dayjs");
const {
  generateCalendarKeyboard,
  getCalendarBackAction,
  getCalendarDisplayRange,
} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const {
  calculateBikePricing,
  findOverlappingRental,
  getBookingDateTimes,
} = require("../services/rentalService");
const {getVehicleById} = require("../services/vehicleService");
const {isLaravelMode} = require("../config/runtime");
const {preview} = require("../utils/text");
const {vehicleEmoji} = require("../utils/vehicle");
const {botScreenRenderer} = require("../services/botScreenRenderer");

function calendarRange(booking) {
  return getCalendarDisplayRange(
    booking.calendarYear,
    booking.calendarMonth
  );
}

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data;
  const parts = data.split(":");
  const bikeId = Number(parts[2]);
  if (!bikeId || isNaN(bikeId)) {
    const lang = getCtxLang(ctx);
    return ctx.answerCallbackQuery(t(lang, "booking_invalid_bike_id"));
  }

  const booking = ensureBooking(ctx);
  booking.selectedBikeId = bikeId;
  const lang = getCtxLang(ctx);

  if (!booking.startDate || !booking.endDate) {
    booking.step = "select_start_date";
    booking.calendarMonth = dayjs().month() + 1;
    booking.calendarYear = dayjs().year();

    let blockedDays = [];
    if (booking.selectedBikeId) {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db, calendarRange(booking));
    }

    const calendar = generateCalendarKeyboard(
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
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_start_date",
      text: t(lang, "booking_choose_start_date"),
      replyMarkup: calendar,
      returnContext: {
        scenario: booking.scenario,
        categoryId: booking.categoryId || null,
        selectedBikeId: bikeId,
      },
    });
  }

  return finalizeBikeSelection(ctx, booking, bikeId, lang);
};

async function finalizeBikeSelection(
  ctx,
  bookingOverride,
  bikeId,
  langOverride,
  options = {}
) {
  const booking = bookingOverride || ensureBooking(ctx);
  const lang = langOverride || getCtxLang(ctx);
  const renderer = options.renderer || botScreenRenderer;
  const bike = await getVehicleById(db, bikeId);
  if (!bike || bike.is_active === false) {
    return renderer.renderText(ctx, {
      screen: "booking_bike_missing",
      text: t(lang, "booking_bike_not_found"),
      navigationMode: "replace",
    });
  }

  let conflict = null;
  if (!isLaravelMode()) {
    const {startAtIso, endAtIso} = getBookingDateTimes(booking);
    conflict = await findOverlappingRental(db, {
      bikeId,
      startAt: startAtIso,
      endAt: endAtIso,
      startDate: booking.startDate,
      endDate: booking.endDate,
    });
  }

  if (conflict) {
    booking.startDate = null;
    booking.startTime = null;
    booking.endDate = null;
    booking.endTime = null;
    booking.timeSource = null;
    booking.totalPrice = null;
    booking.pricePerDay = null;
    booking.priceUnknown = false;
    booking.step = "select_start_date";
    let blockedDays = [];
    try {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db, calendarRange(booking));
    } catch (e) {
      blockedDays = [];
    }
    return renderer.renderText(ctx, {
      screen: "booking_start_date",
      text: t(lang, "booking_range_conflict_bike"),
      replyMarkup: generateCalendarKeyboard(
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
      ),
      returnContext: {
        scenario: booking.scenario,
        categoryId: booking.categoryId || null,
        selectedBikeId: booking.selectedBikeId,
      },
      navigationMode: "replace",
    });
  }

  let pricing;
  try {
    pricing = await calculateBikePricing(db, {
      bikeId,
      startDate: booking.startDate,
      endDate: booking.endDate,
      startTime: booking.startTime,
      endTime: booking.endTime,
    });
  } catch (error) {
    if (error.code === "season_not_found") {
      return renderer.renderText(ctx, {
        screen: "booking_pricing_missing",
        text: t(lang, "booking_season_not_found"),
        navigationMode: "replace",
      });
    }
    throw error;
  }

  booking.totalPrice = pricing.totalPrice;
  booking.pricePerDay = pricing.pricePerDay;
  booking.priceUnknown = pricing.priceUnknown;

  if (pricing.available === false) {
    booking.totalPrice = null;
    booking.pricePerDay = null;
    return renderer.renderText(ctx, {
      screen: "booking_bike_unavailable",
      text: t(lang, "booking_range_conflict_bike"),
      replyMarkup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:back_to_bikes"}]],
      },
      returnContext: {
        scenario: booking.scenario,
        categoryId: booking.categoryId || null,
      },
      navigationMode: "replace",
    });
  }

  return showBikeSummary(ctx, bike, booking, options);
}

async function showBikeSummary(ctx, bike, bookingOverride, options = {}) {
  const booking = bookingOverride || ensureBooking(ctx);
  const lang = getCtxLang(ctx);
  const renderer = options.renderer || botScreenRenderer;
  let startLabel = booking.startDate
    ? dayjs(booking.startDate).format("DD.MM.YYYY")
    : "-";
  let endLabel = booking.endDate
    ? dayjs(booking.endDate).format("DD.MM.YYYY")
    : "-";
  if (booking.startTime) startLabel += ` ${booking.startTime}`;
  if (booking.endTime) endLabel += ` ${booking.endTime}`;
  const days =
    booking.startDate && booking.endDate
      ? dayjs(booking.endDate).diff(dayjs(booking.startDate), "day") + 1
      : 0;

  const text = tHtml(lang, "booking_bike_summary", {
    emoji: vehicleEmoji(bike),
    name: preview(bike.name, 80),
    start: startLabel,
    end: endLabel,
    days,
    days_label: t(lang, "days_label"),
    price: booking.priceUnknown ? t(lang, "booking_price_tbd") : booking.totalPrice || 0,
    price_per_day: booking.priceUnknown
      ? t(lang, "booking_price_tbd")
      : booking.pricePerDay || 0,
    desc: preview(bike.description, 240),
  });

  const replyMarkup = {
    inline_keyboard: [
      [
        {
          text: t(lang, "booking_add_to_rental_btn"),
          callback_data: "book:add_rental",
        },
      ],
      [
        {
          text: t(lang, "booking_options_btn"),
          callback_data: "book:options",
        },
      ],
      [{
        text: t(lang, "btn_back"),
        callback_data:
          booking.scenario === "bike_first"
            ? "book:calendar_back_end"
            : "book:back_to_bikes",
      }],
    ],
  };

  if (bike.image_url) {
    try {
      return await renderer.renderPhoto(ctx, {
        screen: "booking_bike_summary",
        photo: bike.image_url,
        caption: text,
        parseMode: "HTML",
        replyMarkup,
        returnContext: {
          scenario: booking.scenario,
          categoryId: booking.categoryId || null,
          selectedBikeId: bike.id,
        },
        navigationMode: options.navigationMode || "push",
      });
    } catch (error) {
      console.warn(`Could not send photo for vehicle ${bike.id}:`, error.message);
    }
  }

  return renderer.renderText(ctx, {
    screen: "booking_bike_summary",
    text,
    parseMode: "HTML",
    replyMarkup,
    returnContext: {
      scenario: booking.scenario,
      categoryId: booking.categoryId || null,
      selectedBikeId: bike.id,
    },
    navigationMode: bike.image_url
      ? "replace"
      : options.navigationMode || "push",
  });
}

module.exports.showBikeSummary = showBikeSummary;
module.exports.finalizeBikeSelection = finalizeBikeSelection;
