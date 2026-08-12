require("dotenv").config();
process.env.TZ = process.env.BOOKING_TZ || "Asia/Bangkok";
const {Bot} = require("grammy");
const fs = require("fs");
const path = require("path");
const attachDb = require("./middlewares/attachDb");
const sessionStorage = require("./middlewares/sessionStorage");
const resetFlow = require("./middlewares/resetFlow");
const {t, getCtxLang} = require("./utils/i18n");
const {isLaravelMode, requireLaravelConfig} = require("./config/runtime");

requireLaravelConfig();

const bot = new Bot(process.env.BOT_TOKEN);

if (isLaravelMode()) {
  bot.use(require("./middlewares/redisSessionStorage"));
  bot.use(require("./middlewares/backendContext"));
} else {
  bot.use(attachDb);
  bot.use(sessionStorage);
}
bot.use(resetFlow);

const commandsPath = path.join(__dirname, "commands");
if (fs.existsSync(commandsPath)) {
  fs.readdirSync(commandsPath).forEach((file) => {
    if (file.endsWith(".js") && (!isLaravelMode() || file !== "admin.js")) {
      const commandName = file.replace(".js", "");
      const handler = require(`./commands/${file}`);
      bot.command(commandName, handler);
    }
  });
}

bot.on("message", require("./handlers/message_handler"));

bot.callbackQuery("noop", async (ctx) => {
  try {
    await ctx.answerCallbackQuery();
  } catch (error) {
    // Stale callback queries are harmless.
  }
});
bot.callbackQuery(
  /^menu:(main|book|bookings|prices|conditions|profile|support|about)$/,
  (ctx) => require("./handlers/main_menu").handleMainMenuAction(
    ctx,
    ctx.callbackQuery.data.slice("menu:".length)
  )
);

bot.callbackQuery(/^book:select_date:/, require("./handlers/book_select_date"));
bot.callbackQuery("book:start", require("./commands/book"));
bot.callbackQuery("book:comment", require("./handlers/book_add_comment"));
bot.callbackQuery(/^book:(date_first|bike_first)$/, require("./handlers/book_action"));
bot.callbackQuery("book:add_rental", require("./handlers/book_add_rental"));
bot.callbackQuery("book:draft", require("./handlers/book_draft"));
bot.callbackQuery("book:confirm_rental", require("./handlers/book_confirm"));
bot.callbackQuery(/^book:confirm_remove:[0-9a-f-]+$/i, require("./handlers/book_confirm_remove"));
bot.callbackQuery("book:delete_bike", require("./handlers/book_remove_bike"));
bot.callbackQuery("book:reset_rental", require("./handlers/book_reset"));
bot.callbackQuery(/^book:select_bike:\d+$/, require("./handlers/book_select_bike"));
bot.callbackQuery(/^book:time:(start|end):\d{2}:\d{2}$/, require("./handlers/book_select_time"));
bot.callbackQuery(
  [
    "book:options",
    "book:options:process",
    "book:options:helmets:+",
    "book:options:helmets:-",
    "book:options:delivery",
    "book:options:address",
    "book:options:notes",
    "book:options:time_start",
    "book:options:time_end",
    "book:options:back",
  ],
  require("./handlers/book_options")
);

bot.callbackQuery(/^book:cat:\d+$/, require("./handlers/book_select_category"));
bot.callbackQuery("book:back_to_bikes", require("./handlers/book_back_to_bikes"));
bot.callbackQuery("book:show_available_bikes", require("./handlers/book_show_available_bikes"));
bot.callbackQuery(
  [
    "book:calendar_prev",
    "book:calendar_next",
    "book:calendar_back_end",
    "book:calendar_back_start",
    "book:restart",
    "home",
  ],
  require("./handlers/navigation")
);
bot.callbackQuery(/^lang:set:(ru|en|ua)$/, require("./handlers/language_select"));
bot.callbackQuery(
  /^account:back(?::(main_menu|rent_menu))?$/,
  require("./handlers/account_menu").handleAccountAction
);
bot.callbackQuery(/^prices:/, require("./handlers/prices").handlePricesAction);
bot.callbackQuery(
  /^support:(call|faq|find|back.*)$/,
  require("./handlers/support").handleSupportAction
);
bot.callbackQuery(
  [
    "conditions:open",
    "conditions:accept",
    "conditions:accept_toggle",
    "conditions:back",
  ],
  require("./handlers/conditions").handleConditionsAction
);
bot.callbackQuery(
  [
    "rent:book",
    "rent:menu",
    "rent:current",
    "rent:history",
    "rent:contract",
    "rent:payment",
    "rent:settings",
    "rent:support",
    /^rent:(current|history):\d+$/,
    /^rent:details:[0-9a-f-]+$/i,
    /^rent:cancel:[0-9a-f-]+$/i,
    /^rent:cancel_confirm:[0-9a-f-]+$/i,
  ],
  (ctx, next) => {
    const data = ctx.callbackQuery?.data || "";
    if (data.startsWith("rent:details:")) {
      return require("./handlers/rent_details")(ctx);
    }
    if (data.startsWith("rent:cancel")) {
      return require("./handlers/rent_cancel")(ctx);
    }
    return require("./handlers/rent_menu").handleRentMenuAction(ctx, next);
  }
);

bot.callbackQuery("update:name", async (ctx) => {
  ctx.session.step = "waiting_for_name";
  ctx.session.scenario = null;
  ctx.session.returnToProfile = true;
  await ctx.answerCallbackQuery();
  try {
    await ctx.deleteMessage();
  } catch (e) {
    // ignore delete errors
  }
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_name_prompt"));
});

bot.callbackQuery("update:tel", async (ctx) => {
  ctx.session.step = "waiting_for_phone";
  ctx.session.scenario = null;
  ctx.session.returnToProfile = true;
  await ctx.answerCallbackQuery();
  try {
    await ctx.deleteMessage();
  } catch (e) {
    // ignore delete errors
  }
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_tel_prompt"), {
    reply_markup: {
      keyboard: [
        [{text: t(lang, "share_contact_btn"), request_contact: true}],
      ],
      resize_keyboard: true,
      one_time_keyboard: true,
    },
  });
});

bot.callbackQuery("update:passport", async (ctx) => {
  ctx.session.step = "waiting_for_passport";
  ctx.session.scenario = null;
  ctx.session.returnToProfile = true;
  await ctx.answerCallbackQuery();
  try {
    await ctx.deleteMessage();
  } catch (e) {
    // ignore delete errors
  }
  const lang = getCtxLang(ctx);
  return ctx.reply(t(lang, "update_passport_prompt"));
});

if (!isLaravelMode()) {
  bot.callbackQuery(
    /^admin:rental:(approve|cancel|cancel_skip):\d+$/,
    require("./handlers/admin_rental_action")
  );
  bot.callbackQuery(
    /^admin:(menu|list|deposit)/,
    require("./handlers/admin_menu").handleAdminAction
  );
  bot.callbackQuery(/^admin:bikes/, require("./handlers/admin_bikes").handleAdminBikesCallback);
  bot.callbackQuery(/^admin:bike/, require("./handlers/admin_bikes").handleAdminBikesCallback);
}

bot.catch(async (err) => {
  console.error("Telegram bot handler error:", err.error || err);
  if (!err.ctx?.from) return;

  const message = t(getCtxLang(err.ctx), "booking_service_unavailable");
  try {
    if (err.ctx.callbackQuery) {
      await err.ctx.answerCallbackQuery({text: message, show_alert: true});
    } else {
      await err.ctx.reply(message);
    }
  } catch (replyError) {
    try {
      await err.ctx.reply(message);
    } catch (fallbackError) {
      console.error("Could not send handler failure message:", fallbackError);
    }
  }
});

if (!isLaravelMode()) {
  const {sendDueReminders} = require("./utils/reminders");
  const {startGoogleSheetsCalendarSync} = require("./utils/googleSheetsCalendar");
  setInterval(async () => {
    try {
      await sendDueReminders(bot, require("./connect"));
    } catch (error) {
      console.error("Legacy reminder scheduler error:", error);
    }
  }, 60 * 1000);
  startGoogleSheetsCalendarSync(require("./connect"));
}

module.exports = bot;
