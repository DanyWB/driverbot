const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {sendAdminMenu} = require("../handlers/admin_menu");

module.exports = async (ctx) => {
  const lang = getCtxLang(ctx);
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user || !user.is_admin) {
    return ctx.reply(t(lang, "admin_not_allowed"));
  }

  if (ctx.callbackQuery) {
    try {
      await ctx.answerCallbackQuery();
    } catch (e) {
      // ignore
    }
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore
    }
  }

  return sendAdminMenu(ctx, lang);
};
