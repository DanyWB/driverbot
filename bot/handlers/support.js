const {t, getCtxLang} = require("../utils/i18n");
const {getConfiguration} = require("../services/laravelGateway");
const {telegramUsernameUrl} = require("../utils/constants");
const {botScreenRenderer} = require("../services/botScreenRenderer");

function supportKeyboard(lang, backTarget = "menu", managerTelegram = null) {
  const adminUrl = telegramUsernameUrl(managerTelegram);
  const rows = [
    [{text: t(lang, "support_btn_call"), callback_data: "support:call"}],
    [{text: t(lang, "support_btn_faq"), callback_data: "support:faq"}],
    [{text: t(lang, "support_btn_find"), callback_data: "support:find"}],
  ];
  if (adminUrl) {
    rows.push([{text: t(lang, "support_btn_admin"), url: adminUrl}]);
  }
  rows.push([
    {
      text:
        ["start", "main_menu"].includes(backTarget)
          ? t(lang, "btn_main_menu")
          : t(lang, "btn_back"),
      callback_data: `support:back:${backTarget}`,
    },
  ]);
  return {
    inline_keyboard: rows,
  };
}

async function configuredSupportKeyboard(lang, backTarget) {
  let managerTelegram = null;
  try {
    managerTelegram = (await getConfiguration()).manager_telegram;
  } catch (error) {
    console.warn("[support] Manager Telegram contact is temporarily unavailable.");
  }
  return supportKeyboard(lang, backTarget, managerTelegram);
}

async function sendSupportMenu(ctx, langOverride, options = {}) {
  const lang = langOverride || getCtxLang(ctx);
  const backTarget = options.backTarget ||
    (options.origin === "rent_menu" ? "rent_menu" : "main_menu");
  return (options.renderer || botScreenRenderer).renderText(ctx, {
    screen: "support",
    text: t(lang, "support_info"),
    parseMode: "HTML",
    replyMarkup: await configuredSupportKeyboard(lang, backTarget),
    returnContext: {origin: options.origin || "main_menu"},
    navigationMode: options.navigationMode || "push",
  });
}

async function handleSupportAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  if (action?.startsWith("support:back")) {
    const parts = action.split(":");
    const target = parts[2] || "start";
    if (target === "menu") {
      return sendSupportMenu(ctx, lang, {navigationMode: "back"});
    }
    if (target === "rent_menu") {
      return require("./rent_menu").sendRentMenu(ctx, lang, {
        navigationMode: "back",
      });
    }
    return require("./main_menu").showMainMenu(ctx, lang);
  }

  if (action === "support:call") {
    return botScreenRenderer.renderText(ctx, {
      screen: "support_call",
      text: t(lang, "support_call_text"),
      parseMode: "HTML",
      replyMarkup: {
        inline_keyboard: [
          [
            {
              text: t(lang, "support_call_whatsapp_btn"),
              url: "https://wa.me/66971819946",
            },
          ],
          [{text: t(lang, "btn_back"), callback_data: "support:back:menu"}],
        ],
      },
    });
  }

  if (action === "support:faq") {
    return botScreenRenderer.renderText(ctx, {
      screen: "support_faq",
      text: t(lang, "support_faq_text"),
      parseMode: "HTML",
      replyMarkup: await configuredSupportKeyboard(lang, "menu"),
    });
  }

  if (action === "support:find") {
    return botScreenRenderer.renderText(ctx, {
      screen: "support_find",
      text: t(lang, "support_find_text"),
      parseMode: "HTML",
      replyMarkup: await configuredSupportKeyboard(lang, "menu"),
    });
  }
}

module.exports = {sendSupportMenu, handleSupportAction, supportKeyboard};
