const {t, getCtxLang} = require("../utils/i18n");

function supportKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, "support_btn_call"), callback_data: "support:call"}],
      [{text: t(lang, "support_btn_faq"), callback_data: "support:faq"}],
      [{text: t(lang, "support_btn_find"), callback_data: "support:find"}],
      [{text: t(lang, "btn_back"), callback_data: "support:back"}],
    ],
  };
}

async function sendSupportMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  return ctx.reply(t(lang, "support_info"), {
    parse_mode: "HTML",
    reply_markup: supportKeyboard(lang),
  });
}

async function handleSupportAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  if (action === "support:back") {
    return sendSupportMenu(ctx, lang);
  }

  if (action === "support:call") {
    return ctx.editMessageText(t(lang, "support_call_text"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: t(lang, "support_call_whatsapp_btn"),
              url: "https://wa.me/66999999999",
            },
          ],
          [{text: t(lang, "btn_back"), callback_data: "support:back"}],
        ],
      },
    });
  }

  if (action === "support:faq") {
    return ctx.editMessageText(t(lang, "support_faq_text"), {
      parse_mode: "HTML",
      reply_markup: supportKeyboard(lang),
    });
  }

  if (action === "support:find") {
    return ctx.editMessageText(t(lang, "support_find_text"), {
      parse_mode: "HTML",
      reply_markup: supportKeyboard(lang),
    });
  }
}

module.exports = {sendSupportMenu, handleSupportAction};
