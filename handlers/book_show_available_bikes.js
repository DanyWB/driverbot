const db = require("../connect");
const dayjs = require("dayjs");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;

  if (!booking || !booking.startDate || !booking.endDate) {
    return ctx.answerCallbackQuery("Даты аренды не выбраны.");
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
      "😔 К сожалению, нет доступных байков на выбранные даты.",
      {
        reply_markup: {
          inline_keyboard: [[{text: "⬅️ Назад", callback_data: "book:restart"}]],
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

  keyboard.push([{text: "⬅️ Назад", callback_data: "book:restart"}]);

  await ctx.editMessageText("🏍️ Доступные байки:", {
    reply_markup: {inline_keyboard: keyboard},
  });
};
