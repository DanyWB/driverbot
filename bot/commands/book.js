const {createEmptyBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");
const {getUserByTelegramId} = require("../services/userService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx) => {
  const lang = getCtxLang(ctx);
  const user = await getUserByTelegramId(ctx.from.id);

  if (!user || !user.name) {
    ctx.session.step = "waiting_for_name";
    ctx.session.scenario = "registration";
    return botScreenRenderer.renderText(ctx, {
      screen: "registration_name",
      text: t(lang, "enter_name"),
    });
  }

  if (!user.phone) {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    return botScreenRenderer.renderText(ctx, {
      screen: "registration_phone",
      text: t(lang, "enter_phone"),
    });
  }

  ctx.session.commentReturn = null;
  ctx.session.booking = createEmptyBooking();

  const text = t(lang, "booking_intro");

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_start",
    text,
    parseMode: "HTML",
    replyMarkup: {
      inline_keyboard: [
        [
          {text: t(lang, "booking_btn_date_first"), callback_data: "book:date_first"},
        ],
        [
          {text: t(lang, "booking_btn_bike_first"), callback_data: "book:bike_first"},
        ],
      ],
    },
    returnContext: {origin: "main_menu"},
  });
};
