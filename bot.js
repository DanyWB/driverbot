require("dotenv").config();
const {Bot} = require("grammy");
const fs = require("fs");
const path = require("path");
const db = require("./connect");
// Инициализация бота
const bot = new Bot(process.env.BOT_TOKEN);

// Мидлвары
// Подключение базы в ctx
bot.use((ctx, next) => {
  ctx.db = db;
  return next();
});
// Подключение PostgreSQL-сессий
bot.use(async (ctx, next) => {
  if (!ctx.from) return next();

  const row = await db("sessions").where({user_id: ctx.from.id}).first();
  ctx.session = row ? row.data : {};

  await next();

  if (ctx.session) {
    await db("sessions")
      .insert({user_id: ctx.from.id, data: ctx.session})
      .onConflict("user_id")
      .merge({data: ctx.session});
  }
});
bot.use(async (ctx, next) => {
  const text = ctx.message?.text;
  if (text && text.startsWith("/")) {
    // Пользователь вводит команду вручную → сбросим шаг и сценарий
    ctx.session.step = null;
    ctx.session.scenario = null;
  }

  await next();
});
// bot.use(async (ctx, next) => {
//   const isCallbackNavigation = ctx.callbackQuery?.data?.startsWith("go_");
//   if (isCallbackNavigation) {
//     ctx.session.step = null;
//     ctx.session.scenario = null;
//   }
//   await next();
// });
bot.use(async (ctx, next) => {
  if (ctx.callbackQuery) {
    // Любая inline-кнопка → сбрасываем активный шаг и сценарий
    ctx.session.step = null;
    ctx.session.scenario = null;
  }
  await next();
});
//  Пожелания в аренде
bot.use(async (ctx, next) => {
  if (ctx.message?.text) {
    const step = ctx.session?.step;
    const text = ctx.message.text;
    const telegramId = ctx.from.id;

    if (step === "awaiting_comment") {
      // Получаем пользователя
      const user = await db("users").where({telegram_id: telegramId}).first();
      if (!user) {
        return ctx.reply("❌ Пользователь не найден.");
      }

      // Обновляем все аренды в статусе process
      await db("rentals")
        .where({user_id: user.id, status: "process"})
        .update({comment: text});

      ctx.session.step = null;

      return ctx.reply("✅ Пожелания добавлены.", {
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: "🔙 Вернуться к аренде",
                callback_data: "book:add_rental",
              },
            ],
          ],
        },
      });
    }
  }
  await next();
});

//
// Автоматическая регистрация всех команд из папки commands/
const commandsPath = path.join(__dirname, "commands");
if (fs.existsSync(commandsPath)) {
  fs.readdirSync(commandsPath).forEach((file) => {
    if (file.endsWith(".js")) {
      const commandName = file.replace(".js", "");
      const handler = require(`./commands/${file}`);
      bot.command(commandName, handler);
    }
  });
}

bot.on("message", require("./handlers/message_handler"));

bot.callbackQuery(/^book:select_date:/, require("./handlers/book_select_date"));
bot.callbackQuery("book:start", require("./commands/book"));
bot.callbackQuery("book:comment", require("./handlers/book_add_comment"));
bot.callbackQuery(
  "book:calendar_prev",
  require("./handlers/book_calendar_prev")
);
bot.callbackQuery(
  "book:calendar_next",
  require("./handlers/book_calendar_next")
);
bot.callbackQuery(
  /^book:(date_first|bike_first)$/,
  require("./handlers/book_action")
);
bot.callbackQuery("book:add_rental", require("./handlers/book_add_rental"));
bot.callbackQuery(
  /^book:calendar_(prev|next)$/,
  require("./handlers/book_calendar_nav")
);
bot.callbackQuery("book:confirm_rental", require("./handlers/book_confirm"));
bot.callbackQuery(
  /^book:confirm_remove:\d+$/,
  require("./handlers/book_confirm_remove")
);
bot.callbackQuery("book:delete_bike", require("./handlers/book_remove_bike"));
bot.callbackQuery("book:reset_rental", require("./handlers/book_reset"));
bot.callbackQuery(
  /^book:select_bike:\d+$/,
  require("./handlers/book_select_bike")
);

bot.callbackQuery(/^book:cat:\d+$/, require("./handlers/book_select_category"));
bot.callbackQuery(
  "book:show_available_bikes",
  require("./handlers/book_show_available_bikes")
);
bot.callbackQuery(
  /^book:select_date:\d{4}-\d{2}-\d{2}$/,
  require("./handlers/calendar_handler")
);
//
// bot.callbackQuery("add_name", require("./commands/add_name"));
// bot.callbackQuery("add_tel", require("./commands/add_tel"));
// bot.callbackQuery("add_passport", require("./commands/add_passport"));
bot.callbackQuery("update:name", async (ctx) => {
  ctx.session.step = "waiting_for_name";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  return ctx.reply("👤 Введите новое имя:");
});

bot.callbackQuery("update:tel", async (ctx) => {
  ctx.session.step = "waiting_for_phone";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  return ctx.reply("📞 Введите новый номер телефона в международном формате:");
});

bot.callbackQuery("update:passport", async (ctx) => {
  ctx.session.step = "waiting_for_passport";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  return ctx.reply("🖼 Пожалуйста, отправьте фото паспорта:");
});
bot.callbackQuery(
  /^admin:rental:(approve|cancel):\d+$/,
  require("./handlers/admin_rental_action")
);

//
bot.catch((err) => {
  console.error("Ошибка в обработке:", err);
});

module.exports = bot;
