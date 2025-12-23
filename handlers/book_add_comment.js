const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  ctx.session.step = "awaiting_comment";
  const lang = getCtxLang(ctx);

  await ctx.editMessageText(t(lang, "booking_comment_prompt"), {
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "btn_back"), callback_data: "book:add_rental"}],
      ],
    },
  });
};
