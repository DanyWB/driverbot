// utils/setCommands.js

async function setUserCommands(user, ctx) {
  const botApi = ctx.api;
  const telegramId = user.telegram_id;

  const commands = [
    {command: "start", description: "🏠 Главное меню"},
    {command: "book", description: "📅 Начать бронирование"},
    {command: "add_name", description: "✏️ Изменить имя"},
    {command: "add_tel", description: "📞 Изменить номер"},
    {command: "add_passport", description: "🪪 Загрузить паспорт"},
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
