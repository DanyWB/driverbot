const db = require("../connect");
const dayjs = require("dayjs");
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");

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
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
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
  const bike = await db("bikes").where({id: bikeId}).first();
  if (!bike) {
    return ctx.editMessageText(t(lang, "booking_bike_not_found"));
  }

  const {makeDateTime} = require("../utils/timeSlots");
  const {applyOverlapCondition} = require("../utils/overlap");
  const startAt = makeDateTime(booking.startDate, booking.startTime)?.toISOString();
  const endAt = makeDateTime(booking.endDate, booking.endTime)?.toISOString();

  const conflict = await db("rentals")
    .where("bike_id", bikeId)
    .whereNotIn("status", ["cancelled", "cancelled_by_client"])
    .andWhere((qb) =>
      applyOverlapCondition(qb, startAt, endAt, booking.startDate, booking.endDate)
    )
    .first();

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
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
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

  // Dates for pricing calculation (time is optional)
  const start = startAt ? dayjs(startAt) : dayjs(booking.startDate);
  const end = endAt ? dayjs(endAt) : dayjs(booking.endDate);

  const month = start.month() + 1;
  const seasons = await db("seasons").select("id", "months");
  const matchingSeason = seasons.find((season) =>
    season.months.includes(month)
  );

  if (!matchingSeason) {
    return ctx.editMessageText(t(lang, "booking_season_not_found"));
  }

  const seasonId = matchingSeason.id;

  const days = end.diff(start, "day") + 1;

  let daysType = "1d";
  if (start.date() === end.date() && end.diff(start, "month") === 1) {
    daysType = "month";
  } else if (days >= 1 && days <= 6) {
    daysType = "1d";
  } else if (days >= 7 && days <= 13) {
    daysType = "7d";
  } else if (days >= 14 && days <= 20) {
    daysType = "14d";
  } else if (days >= 21 && days <= 29) {
    daysType = "21d";
  } else {
    daysType = "month";
  }

  const priceRow = await db("bike_prices")
    .where({bike_id: bikeId, season_id: seasonId, days_type: daysType})
    .first();

  const pricePerDay = priceRow ? Number(priceRow.price_per_day) : null;
  const totalPrice = priceRow ? Math.round(pricePerDay * days) : 0;
  booking.totalPrice = totalPrice;
  booking.pricePerDay = pricePerDay;
  booking.priceUnknown = !priceRow;

  return showBikeSummary(ctx, bike, booking);
}

function showBikeSummary(ctx, bike, bookingOverride) {
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

  const text = t(lang, "booking_bike_summary", {
    name: bike.name,
    start: startLabel,
    end: endLabel,
    days,
    days_label: t(lang, "days_label"),
    price: booking.priceUnknown ? t(lang, "booking_price_tbd") : booking.totalPrice || 0,
    price_per_day: booking.priceUnknown
      ? t(lang, "booking_price_tbd")
      : booking.pricePerDay || 0,
    desc: bike.description || "",
  });

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
