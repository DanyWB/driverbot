const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const dayjs = require("dayjs");
const {USER_CANCELLABLE_RENTAL_STATUSES} = require("../utils/rentalStatus");
const {cancelRentalByUser} = require("../services/rentalService");
const {isLaravelMode} = require("../config/runtime");
const {botScreenRenderer} = require("../services/botScreenRenderer");

function backKeyboard(lang) {
  return {
    inline_keyboard: [[
      {text: t(lang, "rent_action_back"), callback_data: "rent:current"},
    ]],
  };
}

module.exports = async (ctx) => {
  if (isLaravelMode()) return require("./laravel_rent_cancel")(ctx);
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":"); // rent:cancel:ID or rent:cancel_confirm:ID
  const action = parts[1];
  const rentalId = Number(parts[2]);
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) {
    return botScreenRenderer.renderText(ctx, {
      screen: "rent_cancel_error",
      text: t(lang, "not_registered"),
      navigationMode: "replace",
    });
  }

  const rental = await db("rentals").where({id: rentalId, user_id: user.id}).first();
  if (!rental) {
    return botScreenRenderer.renderText(ctx, {
      screen: "rent_cancel_error",
      text: t(lang, "rent_current_empty"),
      replyMarkup: backKeyboard(lang),
      navigationMode: "replace",
    });
  }

  if (action === "cancel") {
    if (!USER_CANCELLABLE_RENTAL_STATUSES.includes(rental.status)) {
      return botScreenRenderer.renderText(ctx, {
        screen: "rent_cancel_unavailable",
        text: t(lang, "rent_cannot_cancel"),
        replyMarkup: backKeyboard(lang),
      });
    }
    return botScreenRenderer.renderText(ctx, {
      screen: "rent_cancel_confirm",
      text: t(lang, "rent_cancel_confirm", {id: rental.booking_public_id || rental.id}),
      replyMarkup: {
        inline_keyboard: [
          [{
            text: t(lang, "rent_cancel_confirm_action"),
            callback_data: `rent:cancel_confirm:${rentalId}`,
          }],
          ...backKeyboard(lang).inline_keyboard,
        ],
      },
      returnContext: {backAction: "rent:current"},
    });
  }

  if (action === "cancel_confirm") {
    const result = await cancelRentalByUser(db, {rentalId, userId: user.id});
    if (result.status !== "cancelled") {
      return botScreenRenderer.renderText(ctx, {
        screen: "rent_cancel_unavailable",
        text: t(lang, "rent_cannot_cancel"),
        replyMarkup: backKeyboard(lang),
        navigationMode: "replace",
      });
    }

    const cancelledRental = result.rental;

    // This is a transactional notification, so it intentionally does not use
    // the user's single active UI message.
    const admin = await db("users").where({is_admin: true}).first();
    if (admin) {
      const startLabel = cancelledRental.start_at
        ? dayjs(cancelledRental.start_at).format("DD.MM.YYYY HH:mm")
        : dayjs(cancelledRental.start_date).format("DD.MM.YYYY");
      const endLabel = cancelledRental.end_at
        ? dayjs(cancelledRental.end_at).format("DD.MM.YYYY HH:mm")
        : dayjs(cancelledRental.end_date).format("DD.MM.YYYY");
      const textAdmin = tHtml(lang, "admin_cancel_by_client", {
        id: cancelledRental.booking_public_id || cancelledRental.id,
        user: user.name || "-",
        username: user.telegram_name || "-",
        phone: user.phone || "-",
        start: startLabel,
        end: endLabel,
      });
      await ctx.api.sendMessage(admin.telegram_id, textAdmin, {
        parse_mode: "HTML",
      });
    }

    return botScreenRenderer.renderText(ctx, {
      screen: "rent_cancelled",
      text: t(lang, "rent_cancelled"),
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "rent_btn_current"), callback_data: "rent:current"}],
          [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
        ],
      },
      navigationMode: "replace",
    });
  }
};
