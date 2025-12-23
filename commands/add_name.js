const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  ctx.session.step = "waiting_for_name";
  ctx.session.scenario = null;
  const lang = getCtxLang(ctx);
  await ctx.reply(t(lang, "enter_name"));
};
