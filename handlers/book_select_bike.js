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
        }
      ),
    });
  }

  const bike = await db("bikes").where({id: bikeId}).first();
  if (!bike) {
    return ctx.editMessageText(t(lang, "booking_bike_not_found"));
  }

  const blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
  const start = dayjs(booking.startDate);
  const end = dayjs(booking.endDate);
  const selectedDates = [];
  let current = start;
  while (current.isBefore(end) || current.isSame(end, "day")) {
    selectedDates.push(current.format("YYYY-MM-DD"));
    current = current.add(1, "day");
  }
  const hasConflict = selectedDates.some((d) => blockedDays.includes(d));
  if (hasConflict) {
    booking.startDate = null;
    booking.endDate = null;
    booking.step = "select_start_date";
    return ctx.editMessageText(t(lang, "booking_range_conflict_bike"), {
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

  const totalPrice = priceRow ? priceRow.price_per_day * days : 10;

  booking.totalPrice = totalPrice;

  const text = t(lang, "booking_bike_summary", {
    name: bike.name,
    start: dayjs(booking.startDate).format("DD.MM.YYYY"),
    end: dayjs(booking.endDate).format("DD.MM.YYYY"),
    days,
    days_label: t(lang, "days_label"),
    price: totalPrice,
    desc: bike.description || "",
  });

  await ctx.editMessageText(text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [
          {
            text: t(lang, "booking_add_to_rental_btn"),
            callback_data: "book:add_rental",
          },
        ],
        [{text: t(lang, "btn_back"), callback_data: "book:show_available_bikes"}],
      ],
    },
  });
};
