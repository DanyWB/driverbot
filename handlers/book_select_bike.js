const db = require("../connect");
const dayjs = require("dayjs");
const isSameOrBefore = require("dayjs/plugin/isSameOrBefore");
dayjs.extend(isSameOrBefore);
const {generateCalendarKeyboard} = require("../utils/calendar");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data;
  const parts = data.split(":");
  const bikeId = Number(parts[2]);
  if (!bikeId || isNaN(bikeId)) {
    return ctx.answerCallbackQuery("❌ Неверный формат ID байка.");
  }
  const booking = ctx.session.booking || {};
  ctx.session.booking = booking;
  booking.selectedBikeId = bikeId;

  if (!booking.startDate || !booking.endDate) {
    booking.step = "select_start_date";
    booking.calendarMonth = dayjs().month() + 1;
    booking.calendarYear = dayjs().year();

    let blockedDays = [];
    if (booking.selectedBikeId) {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);

      console.log(blockedDays);
    }

    return ctx.editMessageText("📆 Пожалуйста, выберите дату начала аренды.", {
      reply_markup: generateCalendarKeyboard(
        booking.calendarYear,
        booking.calendarMonth,
        blockedDays
      ),
    });
  }

  // Получаем байк
  const bike = await db("bikes").where({id: bikeId}).first();
  if (!bike) {
    return ctx.editMessageText("❌ Байк не найден.");
  }

  // Определяем месяц и сезон
  const month = dayjs(booking.startDate).month() + 1;
  const seasons = await db("seasons").select("id", "months");
  const matchingSeason = seasons.find((season) =>
    season.months.includes(month)
  );

  if (!matchingSeason) {
    return ctx.editMessageText(
      "❌ Не удалось определить сезон по выбранной дате."
    );
  }

  const seasonId = matchingSeason.id;

  // Получаем цену
  const start = dayjs(booking.startDate);
  const end = dayjs(booking.endDate);
  const days = end.diff(start, "day") + 1;

  let daysType = "1d";
  // Проверяем полный месяц: с 1 числа по 1 число и ровно месяц разницы
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
    daysType = "month"; // если пользователь выбрал что-то длиннее 29 дней, по бизнес-логике возможно нужно ограничить/сделать ошибку
  }

  const priceRow = await db("bike_prices")
    .where({bike_id: bikeId, season_id: seasonId, days_type: daysType})
    .first();

  const totalPrice = priceRow ? priceRow.price_per_day * days : 10;

  booking.totalPrice = totalPrice;

  const text = `🛵 <b>${bike.name}</b>\n\n📅 <b>Период аренды:</b> ${dayjs(
    booking.startDate
  ).format("DD.MM.YYYY")} — ${dayjs(booking.endDate).format(
    "DD.MM.YYYY"
  )} (${days} дней)\n💰 <b>Стоимость:</b> ${totalPrice} ฿\n\n${
    bike.description || ""
  }`;

  await ctx.editMessageText(text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [{text: "➕ Добавить в аренду", callback_data: "book:add_rental"}],
        [{text: "🔙 Назад", callback_data: "book:show_available_bikes"}],
      ],
    },
  });
};
