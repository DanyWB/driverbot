const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const sessionCart = require("../services/sessionCartService");
const {getUserByTelegramId} = require("../services/userService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const bikeId = data.split(":").slice(2).join(":");
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  const user = isLaravelMode()
    ? await getUserByTelegramId(telegramId)
    : await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_remove_error",
      text: t(lang, "not_registered"),
      navigationMode: "replace",
    });
  }

  const deleted = isLaravelMode()
    ? sessionCart.remove(ctx, bikeId)
    : await db("rentals")
        .where({id: Number(bikeId), user_id: user.id, status: "process"})
        .del();

  if (!deleted) {
    return ctx.answerCallbackQuery(t(lang, "booking_delete_failed"));
  }
  ctx.session.booking = null;
  const rentals = isLaravelMode()
    ? sessionCart.getCart(ctx)
    : await db("rentals")
        .join("bikes", "rentals.bike_id", "bikes.id")
        .where("user_id", user.id)
        .andWhere("status", "process")
        .select("rentals.id", "bikes.name", "rentals.start_date", "rentals.end_date");

  if (!rentals.length) {
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_draft_empty",
      text: t(lang, "booking_no_bikes_in_process"),
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
          [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
        ],
      },
      navigationMode: "replace",
    });
  }

  let text = t(lang, "booking_bike_removed") + "\n\n";
  rentals.forEach((r) => {
    text += `• ${r.name}: ${r.start_date || ""} - ${r.end_date || ""}\n`;
  });

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_draft",
    text,
    replyMarkup: {
      inline_keyboard: [
        [{text: t(lang, "booking_confirm_btn"), callback_data: "book:confirm_rental"}],
        [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
        [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
      ],
    },
    navigationMode: "replace",
  });
};
