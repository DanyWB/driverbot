const {t, getCtxLang} = require("../utils/i18n");
const {getAdminTelegramUrl} = require("../utils/constants");

function supportKeyboard(lang, backTarget = "menu") {
  const adminUrl = getAdminTelegramUrl();
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
        backTarget === "start"
          ? t(lang, "btn_main_menu")
          : t(lang, "btn_back"),
      callback_data: `support:back:${backTarget}`,
    },
  ]);
  return {
    inline_keyboard: rows,
  };
}

async function sendSupportMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  return ctx.reply(t(lang, "support_info"), {
    parse_mode: "HTML",
    reply_markup: supportKeyboard(lang, "start"),
  });
}

async function handleSupportAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  if (action?.startsWith("support:back")) {
    const parts = action.split(":");
    const target = parts[2] || "start";
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    if (target === "menu") {
      return sendSupportMenu(ctx, lang);
    }
    return require("../commands/start")(ctx);
  }

  if (action === "support:call") {
    return ctx.editMessageText(t(lang, "support_call_text"), {
      parse_mode: "HTML",
      reply_markup: {
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
    return ctx.editMessageText(t(lang, "support_faq_text"), {
      parse_mode: "HTML",
      reply_markup: supportKeyboard(lang, "menu"),
    });
  }

  if (action === "support:find") {
    return ctx.editMessageText(t(lang, "support_find_text"), {
      parse_mode: "HTML",
      reply_markup: supportKeyboard(lang, "menu"),
    });
  }
}

module.exports = {sendSupportMenu, handleSupportAction};
