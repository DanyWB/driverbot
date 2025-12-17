const db = require("../connect");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply("Вы не зарегистрированы.");
  }

  await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process")
    .del();

  ctx.session.booking = null;

  await ctx.editMessageText("♻️ Аренда сброшена. Вы можете начать заново.", {
    reply_markup: {
      inline_keyboard: [
        [{text: "📅 Сначала выбрать дату", callback_data: "book:date_first"}],
        [{text: "🏍️ Сначала выбрать байк", callback_data: "book:bike_first"}],
        [{text: "🏠 В меню", callback_data: "home"}],
      ],
    },
  });
};
