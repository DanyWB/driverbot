const {t, getCtxLang} = require("../utils/i18n");
const {buildDraftMenuPayload} = require("./booking_draft_menu");
const {botScreenRenderer} = require("../services/botScreenRenderer");

async function showBookingDraft(ctx, options = {}) {
  const lang = options.lang || getCtxLang(ctx);
  const payload = await buildDraftMenuPayload(ctx, {
    lang,
    user: options.user,
    backAction: options.backAction || "menu:main",
    backText: options.backText,
  });
  const renderer = options.renderer || botScreenRenderer;

  if (payload?.error) {
    return renderer.renderText(ctx, {
      screen: "booking_draft_error",
      text: payload.error,
      navigationMode: "replace",
    });
  }
  if (payload?.empty) {
    return renderer.renderText(ctx, {
      screen: "booking_draft_empty",
      text: t(lang, "booking_no_bikes_in_process"),
      replyMarkup: {
        inline_keyboard: [[
          {text: t(lang, "btn_main_menu"), callback_data: "menu:main"},
        ]],
      },
      navigationMode: "replace",
    });
  }

  return renderer.renderText(ctx, {
    screen: "booking_confirmation",
    text: options.notice ? `${options.notice}\n\n${payload.text}` : payload.text,
    parseMode: "HTML",
    replyMarkup: payload.reply_markup,
    returnContext: {origin: options.origin || "booking_flow"},
    navigationMode: options.navigationMode || "push",
  });
}

async function handleBookDraft(ctx) {
  return showBookingDraft(ctx);
}

module.exports = handleBookDraft;
module.exports.showBookingDraft = showBookingDraft;
