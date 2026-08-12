const {t, getCtxLang} = require("../utils/i18n");
const {BotApiError} = require("../services/botApiClient");
const {cancelBooking, getBooking} = require("../services/laravelGateway");
const {botScreenRenderer} = require("../services/botScreenRenderer");

function managerMessage(lang, booking, error = null) {
  const manager = error?.details?.manager_telegram
    || booking?.cancellation?.manager_telegram;
  if (!manager) return t(lang, "rent_cancel_manager_contact_missing");
  return t(lang, "rent_cancel_manager_required", {manager});
}

function resolveCancelBackAction(ctx, publicId) {
  if (ctx.session?.currentScreen === "rent_details") {
    return `rent:details:${publicId}`;
  }
  const page = Math.max(0, Number(ctx.session?.currentRentalsPage) || 0);
  return `rent:current:${page}`;
}

async function handleLaravelRentCancelWithDeps(ctx, dependencies = {}) {
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":");
  const action = parts[1];
  const publicId = parts.slice(2).join(":");
  const lang = getCtxLang(ctx);
  const renderer = dependencies.renderer || botScreenRenderer;
  const loadBooking = dependencies.getBooking || getBooking;
  const requestCancellation = dependencies.cancelBooking || cancelBooking;
  const booking = await loadBooking(ctx.from.id, publicId);
  const storedBackAction = ctx.session?.rentCancelBackAction;
  const backAction = action === "cancel_confirm" && storedBackAction
    ? storedBackAction
    : resolveCancelBackAction(ctx, booking.booking_public_id || publicId);

  ctx.session.rentCancelBackAction = backAction;

  const backKeyboard = {
    inline_keyboard: [[
      {text: t(lang, "rent_action_back"), callback_data: backAction},
    ]],
  };

  if (action === "cancel") {
    if (booking.cancellation?.requires_manager) {
      return renderer.renderText(ctx, {
        screen: "rent_cancel_manager",
        text: managerMessage(lang, booking),
        replyMarkup: backKeyboard,
        returnContext: {backAction},
      });
    }
    if (!booking.can_cancel) {
      return renderer.renderText(ctx, {
        screen: "rent_cancel_unavailable",
        text: t(lang, "rent_cannot_cancel"),
        replyMarkup: backKeyboard,
        returnContext: {backAction},
      });
    }
    return renderer.renderText(ctx, {
      screen: "rent_cancel_confirm",
      text: t(lang, "rent_cancel_confirm", {id: booking.booking_public_id}),
      replyMarkup: {
        inline_keyboard: [
          [{
            text: t(lang, "rent_cancel_confirm_action"),
            callback_data: `rent:cancel_confirm:${booking.booking_public_id}`,
          }],
          [{text: t(lang, "rent_action_back"), callback_data: backAction}],
        ],
      },
      returnContext: {backAction, publicId: booking.booking_public_id},
    });
  }

  if (action === "cancel_confirm") {
    try {
      await requestCancellation(ctx, publicId);
    } catch (error) {
      if (error instanceof BotApiError && error.code === "CLIENT_CANCELLATION_REQUIRES_MANAGER") {
        return renderer.renderText(ctx, {
          screen: "rent_cancel_manager",
          text: managerMessage(lang, booking, error),
          replyMarkup: backKeyboard,
          returnContext: {backAction},
          navigationMode: "replace",
        });
      }
      throw error;
    }
    delete ctx.session.rentCancelBackAction;
    return renderer.renderText(ctx, {
      screen: "rent_cancelled",
      text: t(lang, "rent_cancelled"),
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "rent_btn_current"), callback_data: "rent:current:0"}],
          [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
        ],
      },
      navigationMode: "replace",
    });
  }
}

async function handleLaravelRentCancel(ctx) {
  return handleLaravelRentCancelWithDeps(ctx);
}

module.exports = handleLaravelRentCancel;
module.exports.handleLaravelRentCancelWithDeps = handleLaravelRentCancelWithDeps;
module.exports.managerMessage = managerMessage;
module.exports.resolveCancelBackAction = resolveCancelBackAction;
