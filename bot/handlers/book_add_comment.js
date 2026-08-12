const {t, getCtxLang} = require("../utils/i18n");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  ctx.session.step = "awaiting_comment";
  const lang = getCtxLang(ctx);
  const commentReturn = ctx.session.commentReturn;
  const backCallback =
    commentReturn || (ctx.session.booking ? "book:draft" : "rent:current");
  const backText =
    backCallback === "rent:current"
      ? t(lang, "rent_current_back_btn")
      : t(lang, "btn_back");

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_comment",
    text: t(lang, "booking_comment_prompt"),
    replyMarkup: {
      inline_keyboard: [
        [{text: backText, callback_data: backCallback}],
      ],
    },
    returnContext: {backAction: backCallback},
  });
};
