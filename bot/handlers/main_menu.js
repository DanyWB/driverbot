const {t, getCtxLang} = require("../utils/i18n");
const {getInlineMainMenuKeyboard} = require("../utils/mainMenu");
const {botScreenRenderer} = require("../services/botScreenRenderer");

async function showMainMenu(ctx, langOverride, options = {}) {
  const lang = langOverride || getCtxLang(ctx);
  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }

  return (options.renderer || botScreenRenderer).renderText(ctx, {
    screen: "main_menu",
    text: t(lang, "menu_title"),
    parseMode: "HTML",
    replyMarkup: getInlineMainMenuKeyboard(lang),
    returnContext: null,
    navigationMode: options.navigationMode || "reset",
  });
}

async function deleteQuickActionMessage(ctx, logger = console) {
  const chatId = ctx.chat?.id || ctx.message?.chat?.id;
  const messageId = ctx.message?.message_id;
  if (!chatId || !messageId || typeof ctx.api?.deleteMessage !== "function") {
    return false;
  }

  try {
    await ctx.api.deleteMessage(chatId, messageId);
    return true;
  } catch (error) {
    logger.warn?.("[main-menu] Could not delete a quick-action message.", {
      chatId,
      messageId,
      error: error?.description || error?.message || String(error),
    });
    return false;
  }
}

async function handleMainMenuAction(ctx, action) {
  const lang = getCtxLang(ctx);

  if (action === "main") return showMainMenu(ctx, lang);
  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }

  if (action === "book") return require("../commands/book")(ctx);
  if (action === "bookings") {
    return require("./rent_menu").sendRentMenu(ctx, lang);
  }
  if (action === "prices") {
    return require("./prices").sendPricesMenu(ctx, lang, {
      origin: "main_menu",
    });
  }
  if (action === "conditions") {
    return require("./conditions").sendConditions(ctx, lang, {
      origin: "main_menu",
    });
  }
  if (action === "profile") {
    return require("./account_menu").sendAccountMenu(ctx, lang);
  }
  if (action === "support") {
    return require("./support").sendSupportMenu(ctx, lang);
  }
  if (action === "about") {
    return botScreenRenderer.renderText(ctx, {
      screen: "about",
      text: t(lang, "about_info"),
      parseMode: "HTML",
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "btn_main_menu"), callback_data: "menu:main"}],
          [{text: t(lang, "main_menu_book"), callback_data: "menu:book"}],
        ],
      },
      returnContext: {origin: "main_menu"},
    });
  }
}

async function handleQuickAction(ctx, action) {
  await deleteQuickActionMessage(ctx);
  return handleMainMenuAction(ctx, action);
}

module.exports = {
  deleteQuickActionMessage,
  handleMainMenuAction,
  handleQuickAction,
  showMainMenu,
};
