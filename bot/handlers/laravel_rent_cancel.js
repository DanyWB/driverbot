const {t, getCtxLang} = require("../utils/i18n");
const {BotApiError} = require("../services/botApiClient");
const {cancelBooking, getBooking} = require("../services/laravelGateway");

function managerMessage(lang, booking, error = null) {
  const manager = error?.details?.manager_telegram
    || booking?.cancellation?.manager_telegram;
  if (!manager) return t(lang, "rent_cancel_manager_contact_missing");
  return t(lang, "rent_cancel_manager_required", {manager});
}

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":");
  const action = parts[1];
  const publicId = parts.slice(2).join(":");
  const lang = getCtxLang(ctx);
  const booking = await getBooking(ctx.from.id, publicId);

  if (action === "cancel") {
    if (booking.cancellation?.requires_manager) {
      return ctx.reply(managerMessage(lang, booking));
    }
    if (!booking.can_cancel) return ctx.reply(t(lang, "rent_cannot_cancel"));
    return ctx.reply(t(lang, "rent_cancel_confirm", {id: booking.booking_public_id}), {
      reply_markup: {
        inline_keyboard: [
          [{text: "✅", callback_data: `rent:cancel_confirm:${booking.booking_public_id}`}],
          [{text: t(lang, "rent_action_back"), callback_data: "rent:current"}],
        ],
      },
    });
  }

  if (action === "cancel_confirm") {
    try {
      await cancelBooking(ctx, publicId);
    } catch (error) {
      if (error instanceof BotApiError && error.code === "CLIENT_CANCELLATION_REQUIRES_MANAGER") {
        return ctx.reply(managerMessage(lang, booking, error));
      }
      throw error;
    }
    return ctx.editMessageText(t(lang, "rent_cancelled"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_main_menu"), callback_data: "home"}]],
      },
    });
  }
};
