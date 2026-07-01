const {t, getCtxLang} = require("../utils/i18n");
const {buildDraftMenuPayload} = require("./booking_draft_menu");

module.exports = async (ctx) => {
  const lang = getCtxLang(ctx);
  const payload = await buildDraftMenuPayload(ctx, {lang, backAction: "home"});

  if (payload?.error) {
    return ctx.reply(payload.error);
  }
  if (payload?.empty) {
    return ctx.reply(t(lang, "booking_no_bikes_in_process"));
  }

  return ctx.editMessageText(payload.text, {
    parse_mode: "HTML",
    reply_markup: payload.reply_markup,
  });
};
