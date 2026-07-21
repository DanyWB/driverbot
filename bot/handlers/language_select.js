const {updateUserLanguage} = require("../services/userService");
const {isSupportedLang, normalizeLang, t} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data || "";
  const lang = data.split(":")[2];

  if (!isSupportedLang(lang)) {
    await ctx.answerCallbackQuery(t("ru", "lang_unsupported"));
    return;
  }

  const normalized = normalizeLang(lang);
  const saved = await updateUserLanguage(ctx.from.id, normalized);

  if (!saved) {
    await ctx.answerCallbackQuery(t("ru", "lang_save_error"));
    return;
  }

  ctx.session.lang = normalized;

  await ctx.answerCallbackQuery();
  await ctx.editMessageText(t(normalized, "lang_saved"));

  return require("../commands/start")(ctx);
};
