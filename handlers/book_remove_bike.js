const db = require("../connect");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply("Пользователь не найден.");
  }

  const rentals = await db("rentals")
    .where({user_id: user.id, status: "process"})
    .join("bikes", "rentals.bike_id", "bikes.id")
    .select("rentals.id as rental_id", "bikes.name", "bikes.id as bike_id");

  if (!rentals || rentals.length === 0) {
    return ctx.reply("У вас нет байков в процессе аренды.");
  }
  try {
    await ctx.deleteMessage();
  } catch (e) {
    console.warn("Не удалось удалить сообщение:", e.message);
  }
  const keyboard = rentals.map((rental) => [
    {
      text: `Удалить ${rental.name}`,
      callback_data: `book:confirm_remove:${rental.rental_id}`,
    },
  ]);

  keyboard.push([{text: "⬅️ Назад", callback_data: "book:add_rental"}]);

  return ctx.reply("🗑️ Выберите байк, который хотите удалить из аренды:", {
    reply_markup: {
      inline_keyboard: keyboard,
    },
  });
};
