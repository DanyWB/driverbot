const {t, getLanguageKeyboard} = require("../utils/i18n");

module.exports = async (ctx) => {
  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }
  return ctx.reply(t("ru", "lang_prompt"), {
    reply_markup: getLanguageKeyboard(),
  });
};
