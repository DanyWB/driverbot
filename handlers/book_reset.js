const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply(t(lang, "not_registered"));
  }

  await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process")
    .del();

  ctx.session.booking = null;

  await ctx.editMessageText(t(lang, "booking_reset_done"), {
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "booking_btn_date_first"), callback_data: "book:date_first"}],
        [{text: t(lang, "booking_btn_bike_first"), callback_data: "book:bike_first"}],
        [{text: t(lang, "btn_home"), callback_data: "home"}],
      ],
    },
  });
};
