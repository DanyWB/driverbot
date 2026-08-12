const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {getCart} = require("../services/sessionCartService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);
  if (isLaravelMode()) {
    const rentals = getCart(ctx);
    if (!rentals.length) {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_remove_bike",
        text: t(lang, "booking_no_bikes_in_process"),
        replyMarkup: {
          inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:draft"}]],
        },
        navigationMode: "replace",
      });
    }
    const keyboard = rentals.map((rental) => [{
      text: t(lang, "booking_delete_bike_item", {name: rental.name}),
      callback_data: `book:confirm_remove:${rental.id}`,
    }]);
    keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:draft"}]);
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_remove_bike",
      text: t(lang, "booking_delete_prompt"),
      replyMarkup: {inline_keyboard: keyboard},
      returnContext: {backAction: "book:draft"},
      navigationMode: "replace",
    });
  }

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_remove_error",
      text: t(lang, "user_not_found"),
      navigationMode: "replace",
    });
  }

  const rentals = await db("rentals")
    .where({user_id: user.id, status: "process"})
    .join("bikes", "rentals.bike_id", "bikes.id")
    .select("rentals.id as rental_id", "bikes.name", "bikes.id as bike_id");

  if (!rentals || rentals.length === 0) {
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_remove_bike",
      text: t(lang, "booking_no_bikes_in_process"),
      replyMarkup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:draft"}]],
      },
      navigationMode: "replace",
    });
  }
  const keyboard = rentals.map((rental) => [
    {
      text: t(lang, "booking_delete_bike_item", {name: rental.name}),
      callback_data: `book:confirm_remove:${rental.rental_id}`,
    },
  ]);

  keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:draft"}]);

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_remove_bike",
    text: t(lang, "booking_delete_prompt"),
    replyMarkup: {inline_keyboard: keyboard},
    returnContext: {backAction: "book:draft"},
    navigationMode: "replace",
  });
};
