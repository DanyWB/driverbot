const db = require("../connect");
const dayjs = require("dayjs");
const {generateCalendarKeyboard} = require("../utils/calendar");
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

function calendarRange(booking) {
  const start = dayjs(
    `${booking.calendarYear}-${String(booking.calendarMonth).padStart(2, "0")}-01`
  ).startOf("week");
  return {startDate: start.format("YYYY-MM-DD"), endDate: start.add(41, "day").format("YYYY-MM-DD")};
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

    return ctx.editMessageText(t(lang, "booking_choose_start_date"), {
      reply_markup: generateCalendarKeyboard(
        booking.calendarYear,
        booking.calendarMonth,
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

  return finalizeBikeSelection(ctx, booking, bikeId, lang);
};

async function finalizeBikeSelection(ctx, bookingOverride, bikeId, langOverride) {
  const booking = bookingOverride || ensureBooking(ctx);
  const lang = langOverride || getCtxLang(ctx);
  const bike = await getVehicleById(db, bikeId);
  if (!bike || bike.is_active === false) {
    return ctx.editMessageText(t(lang, "booking_bike_not_found"));
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
    return ctx.editMessageText(t(lang, "booking_range_conflict_bike"), {
      reply_markup: generateCalendarKeyboard(
        booking.calendarYear,
        booking.calendarMonth,
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
      return ctx.editMessageText(t(lang, "booking_season_not_found"));
    }
    throw error;
  }

  booking.totalPrice = pricing.totalPrice;
  booking.pricePerDay = pricing.pricePerDay;
  booking.priceUnknown = pricing.priceUnknown;

  if (pricing.available === false) {
    booking.totalPrice = null;
    booking.pricePerDay = null;
    return ctx.editMessageText(t(lang, "booking_range_conflict_bike"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:back_to_bikes"}]],
      },
    });
  }

  return showBikeSummary(ctx, bike, booking);
}

async function showBikeSummary(ctx, bike, bookingOverride) {
  const booking = bookingOverride || ensureBooking(ctx);
  const lang = getCtxLang(ctx);
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

  if (bike.image_url && ctx.session.lastPhotoVehicleId !== bike.id) {
    try {
      await ctx.replyWithPhoto(bike.image_url, {caption: bike.name});
      ctx.session.lastPhotoVehicleId = bike.id;
    } catch (error) {
      console.warn(`Could not send photo for vehicle ${bike.id}:`, error.message);
    }
  }

  return ctx.editMessageText(text, {
    parse_mode: "HTML",
    reply_markup: {
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
        [{text: t(lang, "btn_back"), callback_data: "book:back_to_bikes"}],
      ],
    },
  });
}

module.exports.showBikeSummary = showBikeSummary;
module.exports.finalizeBikeSelection = finalizeBikeSelection;
