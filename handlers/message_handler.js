const db = require("../connect");
const {
  handleNameStep,
  handlePhoneStep,
  handlePassportStep,
} = require("./registration_steps");

module.exports = async (ctx) => {
  const step = ctx.session?.step;
  const telegramId = ctx.from.id;

  if (!step) return;

  // Пожелание к аренде
  if (step === "awaiting_comment" && ctx.message?.text) {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      await ctx.reply("Ошибка: пользователь не найден.");
      return;
    }

    await db("rentals")
      .where({user_id: user.id, status: "process"})
      .update({comment: ctx.message.text});

    ctx.session.step = null;

    await ctx.reply("💬 Ваши пожелания сохранены.", {
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: "➕ Добавить байк к аренде",
              callback_data: "book:add_rental",
            },
          ],
        ],
      },
    });
    return;
  }

  if (step === "waiting_for_name") {
    return handleNameStep(ctx);
  }

  if (step === "waiting_for_phone") {
    return handlePhoneStep(ctx);
  }

  if (step === "waiting_for_passport") {
    return handlePassportStep(ctx);
  }
};
