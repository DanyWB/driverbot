require("dotenv").config();
const {Bot} = require("grammy");
const fs = require("fs");
const path = require("path");
const attachDb = require("./middlewares/attachDb");
const sessionStorage = require("./middlewares/sessionStorage");
const resetFlow = require("./middlewares/resetFlow");
const {t, getCtxLang} = require("./utils/i18n");

const bot = new Bot(process.env.BOT_TOKEN);

bot.use(attachDb);
bot.use(sessionStorage);
bot.use(resetFlow);

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
bot.callbackQuery(/^book:(date_first|bike_first)$/, require("./handlers/book_action"));
bot.callbackQuery("book:add_rental", require("./handlers/book_add_rental"));
bot.callbackQuery("book:confirm_rental", require("./handlers/book_confirm"));
bot.callbackQuery(/^book:confirm_remove:\d+$/, require("./handlers/book_confirm_remove"));
bot.callbackQuery("book:delete_bike", require("./handlers/book_remove_bike"));
bot.callbackQuery("book:reset_rental", require("./handlers/book_reset"));
bot.callbackQuery(/^book:select_bike:\d+$/, require("./handlers/book_select_bike"));

bot.callbackQuery(/^book:cat:\d+$/, require("./handlers/book_select_category"));
bot.callbackQuery("book:show_available_bikes", require("./handlers/book_show_available_bikes"));
bot.callbackQuery(/^book:select_date:\d{4}-\d{2}-\d{2}$/, require("./handlers/calendar_handler"));
bot.callbackQuery(["book:calendar_prev", "book:calendar_next", "book:restart", "home"], require("./handlers/navigation"));
bot.callbackQuery(/^lang:set:(ru|en|ua)$/, require("./handlers/language_select"));

bot.callbackQuery("update:name", async (ctx) => {
  ctx.session.step = "waiting_for_name";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_name_prompt"));
});

bot.callbackQuery("update:tel", async (ctx) => {
  ctx.session.step = "waiting_for_phone";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_tel_prompt"));
});

bot.callbackQuery("update:passport", async (ctx) => {
  ctx.session.step = "waiting_for_passport";
  ctx.session.scenario = null;
  await ctx.answerCallbackQuery();
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_passport_prompt"));
});

bot.callbackQuery(
  /^admin:rental:(approve|cancel):\d+$/,
  require("./handlers/admin_rental_action")
);

bot.catch((err) => {
  console.error("Ошибка в обработчике бота:", err);
});

module.exports = bot;
