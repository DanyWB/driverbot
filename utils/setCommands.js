// utils/setCommands.js

async function setUserCommands(user, ctx) {
  const botApi = ctx.api;
  const telegramId = user.telegram_id;

  const commands = [
    {command: "start", description: "Начать"},
    {command: "book", description: "Забронировать байк"},
    {command: "add_name", description: "Указать имя"},
    {command: "add_tel", description: "Указать телефон"},
    {command: "add_passport", description: "Загрузить паспорт"},
  ];

  // Удаляем старые команды для пользователя
  await Promise.all([
    botApi.deleteMyCommands({
      scope: {type: "chat", chat_id: telegramId},
    }),
  ]);

  // Устанавливаем команды
  await botApi.setMyCommands(commands, {
    scope: {type: "chat", chat_id: telegramId},
  });
}

module.exports = {setUserCommands};
