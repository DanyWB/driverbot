const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;
  const lang = getCtxLang(ctx);

  if (!booking || !booking.startDate || !booking.endDate) {
    return ctx.answerCallbackQuery(t(lang, "booking_dates_not_selected"));
  }

  const {startDate, endDate} = booking;

  const busyBikes = await db("rentals")
    .select("bike_id")
    .where("status", "!=", "cancelled")
    .andWhere((builder) => {
      builder
        .whereBetween("start_date", [startDate, endDate])
        .orWhereBetween("end_date", [startDate, endDate])
        .orWhere((q) =>
          q
            .where("start_date", "<=", startDate)
            .andWhere("end_date", ">=", endDate)
        );
    });

  const busyIds = busyBikes.map((b) => b.bike_id);

  const availableBikes = await db("bikes")
    .select("id", "name")
    .whereNotIn("id", busyIds);

  if (availableBikes.length === 0) {
    return ctx.editMessageText(
      t(lang, "booking_no_available_bikes"),
      {
        reply_markup: {
          inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:restart"}]],
        },
      }
    );
  }

  const keyboard = availableBikes.map((bike) => [
    {
      text: bike.name,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);

  keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:restart"}]);

  await ctx.editMessageText(t(lang, "booking_available_bikes_title"), {
    reply_markup: {inline_keyboard: keyboard},
  });
};
