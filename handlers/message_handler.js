const db = require("../connect");
const {
  handleNameStep,
  handlePhoneStep,
  handlePassportStep,
} = require("./registration_steps");
const {detectMainMenuAction} = require("../utils/mainMenu");
const {handleMainMenuAction} = require("./main_menu");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const step = ctx.session?.step;
  const telegramId = ctx.from.id;
  const action = detectMainMenuAction(ctx.message?.text);

  if (action) {
    return handleMainMenuAction(ctx, action);
  }

  if (!step) return;

  if (step === "awaiting_comment" && ctx.message?.text) {
    const lang = getCtxLang(ctx);
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      await ctx.reply(t(lang, "user_not_found"));
      return;
    }

    await db("rentals")
      .where({user_id: user.id, status: "process"})
      .update({comment: ctx.message.text});

    ctx.session.step = null;

    await ctx.reply(t(lang, "booking_comment_saved"), {
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: t(lang, "booking_add_bike_to_rental_btn"),
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
