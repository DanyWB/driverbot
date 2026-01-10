const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  ctx.session.step = "awaiting_comment";
  const lang = getCtxLang(ctx);
  const commentReturn = ctx.session.commentReturn;
  const backCallback =
    commentReturn || (ctx.session.booking ? "book:add_rental" : "rent:current");
  const backText =
    backCallback === "rent:current"
      ? t(lang, "rent_current_back_btn")
      : t(lang, "btn_back");

  await ctx.editMessageText(t(lang, "booking_comment_prompt"), {
    reply_markup: {
      inline_keyboard: [
        [{text: backText, callback_data: backCallback}],
      ],
    },
  });
};
