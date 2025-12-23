// utils/setCommands.js
const {t, normalizeLang} = require("./i18n");

async function setUserCommands(user, ctx, langOverride) {
  const botApi = ctx.api;
  const telegramId = user.telegram_id;
  const lang = normalizeLang(langOverride || user.lang);

  const commands = [
    {command: "start", description: t(lang, "cmd_start")},
    {command: "menu", description: t(lang, "cmd_menu")},
    {command: "book", description: t(lang, "cmd_book")},
    {command: "add_name", description: t(lang, "cmd_add_name")},
    {command: "add_tel", description: t(lang, "cmd_add_tel")},
    {command: "add_passport", description: t(lang, "cmd_add_passport")},
  ];

  await Promise.all([
    botApi.deleteMyCommands({
      scope: {type: "chat", chat_id: telegramId},
    }),
  ]);

  await botApi.setMyCommands(commands, {
    scope: {type: "chat", chat_id: telegramId},
  });
}

module.exports = {setUserCommands};
