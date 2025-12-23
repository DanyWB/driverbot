const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const bikeId = Number(data.split(":")[2]);
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply(t(lang, "not_registered"));
  }

  const deleted = await db("rentals")
    .where({id: bikeId, user_id: user.id, status: "process"})
    .del();

  if (!deleted) {
    return ctx.answerCallbackQuery(t(lang, "booking_delete_failed"));
  }
  ctx.session.booking = null;
  await ctx.editMessageText(t(lang, "booking_bike_removed"), {
    reply_markup: {
      inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:add_rental"}]],
    },
  });
};
