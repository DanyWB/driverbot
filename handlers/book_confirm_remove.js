const db = require("../connect");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const bikeId = Number(data.split(":")[2]);
  const telegramId = ctx.from.id;

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply("Вы не зарегистрированы.");
  }

  const deleted = await db("rentals")
    .where({id: bikeId, user_id: user.id, status: "process"})
    .del();

  if (!deleted) {
    return ctx.answerCallbackQuery("Не удалось удалить байк.");
  }
  ctx.session.booking = null;
  await ctx.editMessageText("🗑️ Байк удалён из вашей аренды.", {
    reply_markup: {
      inline_keyboard: [[{text: "⬅️ Назад", callback_data: "book:add_rental"}]],
    },
  });
};
