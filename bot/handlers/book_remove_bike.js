const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {getCart} = require("../services/sessionCartService");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);
  if (isLaravelMode()) {
    const rentals = getCart(ctx);
    if (!rentals.length) return ctx.reply(t(lang, "booking_no_bikes_in_process"));
    try {
      await ctx.deleteMessage();
    } catch (error) {
      // Message can already be gone after a repeated callback.
    }
    const keyboard = rentals.map((rental) => [{
      text: t(lang, "booking_delete_bike_item", {name: rental.name}),
      callback_data: `book:confirm_remove:${rental.id}`,
    }]);
    keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:draft"}]);
    return ctx.reply(t(lang, "booking_delete_prompt"), {
      reply_markup: {inline_keyboard: keyboard},
    });
  }

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply(t(lang, "user_not_found"));
  }

  const rentals = await db("rentals")
    .where({user_id: user.id, status: "process"})
    .join("bikes", "rentals.bike_id", "bikes.id")
    .select("rentals.id as rental_id", "bikes.name", "bikes.id as bike_id");

  if (!rentals || rentals.length === 0) {
    return ctx.reply(t(lang, "booking_no_bikes_in_process"));
  }
  try {
    await ctx.deleteMessage();
  } catch (e) {
    console.warn("Не удалось удалить сообщение:", e.message);
  }
  const keyboard = rentals.map((rental) => [
    {
      text: t(lang, "booking_delete_bike_item", {name: rental.name}),
      callback_data: `book:confirm_remove:${rental.rental_id}`,
    },
  ]);

  keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:draft"}]);

  return ctx.reply(t(lang, "booking_delete_prompt"), {
    reply_markup: {
      inline_keyboard: keyboard,
    },
  });
};
