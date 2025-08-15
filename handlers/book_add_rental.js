const db = require("../connect");
const dayjs = require("dayjs");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;
  const telegramId = ctx.from.id;

  if (
    !booking ||
    !booking.startDate ||
    !booking.endDate ||
    !booking.selectedBikeId ||
    !booking.totalPrice
  ) {
    return ctx.answerCallbackQuery("❌ Недостаточно данных для аренды.");
  }

  try {
    // Получаем пользователя
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return ctx.reply("❌ Вы не зарегистрированы.");
    }
    const existingRental = await db("rentals")
      .where({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        status: "process",
      })
      .first();

    // Добавляем запись в rentals
    if (!existingRental) {
      await db("rentals").insert({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        start_date: booking.startDate,
        end_date: booking.endDate,
        total_price: booking.totalPrice,
        status: "process",
      });
    }

    // Очищаем временные данные сценария

    // Получаем все байки пользователя со статусом "process"
    const rentals = await db("rentals")
      .join("bikes", "rentals.bike_id", "bikes.id")
      .where("user_id", user.id)
      .andWhere("status", "process")
      .select(
        "rentals.id",
        "bikes.name",
        "rentals.start_date",
        "rentals.end_date",
        "rentals.total_price"
      );

    let text = "📋 <b>Текущая аренда:</b>\n\n";

    for (const rental of rentals) {
      const days =
        dayjs(rental.end_date).diff(dayjs(rental.start_date), "day") + 1;
      text += `🛵 <b>${rental.name}</b>\n📅 ${dayjs(rental.start_date).format(
        "DD.MM.YYYY"
      )} – ${dayjs(rental.end_date).format("DD.MM.YYYY")} (${days} дней)\n💰 ${
        rental.total_price
      } ฿\n\n`;
    }

    await ctx.editMessageText(text, {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: "✅ Подтвердить бронирование",
              callback_data: "book:confirm_rental",
            },
          ],
          [{text: "➕ Добавить байк", callback_data: "book:start"}],
          [{text: "🗑 Удалить байк", callback_data: "book:delete_bike"}],
          [{text: "🔄 Сбросить", callback_data: "book:reset_rental"}],
          [{text: "💬 Пожелания", callback_data: "book:comment"}],
        ],
      },
    });
  } catch (error) {
    console.error("Ошибка при добавлении аренды:", error);
    return ctx.reply("❌ Произошла ошибка при сохранении аренды.");
  }
};
