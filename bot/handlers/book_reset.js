const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {clear} = require("../services/sessionCartService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  if (isLaravelMode()) {
    clear(ctx);
  } else {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_reset_error",
        text: t(lang, "not_registered"),
        navigationMode: "replace",
      });
    }
    await db("rentals")
      .where("user_id", user.id)
      .andWhere("status", "process")
      .del();
  }

  ctx.session.booking = null;

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_reset",
    text: t(lang, "booking_reset_done"),
    replyMarkup: {
      inline_keyboard: [
        [{text: t(lang, "booking_btn_date_first"), callback_data: "book:date_first"}],
        [{text: t(lang, "booking_btn_bike_first"), callback_data: "book:bike_first"}],
        [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
      ],
    },
    navigationMode: "reset",
  });
};
