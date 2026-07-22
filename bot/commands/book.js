const {createEmptyBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");
const {getUserByTelegramId} = require("../services/userService");

module.exports = async (ctx) => {
  const lang = getCtxLang(ctx);
  if (ctx.callbackQuery) {
    try {
      await ctx.answerCallbackQuery();
    } catch (e) {
      // ignore callback errors
    }
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
  }
  const user = await getUserByTelegramId(ctx.from.id);

  if (!user || !user.name) {
    ctx.session.step = "waiting_for_name";
    ctx.session.scenario = "registration";
    return ctx.reply(t(lang, "enter_name"));
  }

  if (!user.phone) {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    return ctx.reply(t(lang, "enter_phone"));
  }

  ctx.session.commentReturn = null;
  ctx.session.booking = createEmptyBooking();

  const text = t(lang, "booking_intro");

  await ctx.reply(text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [
          {text: t(lang, "booking_btn_date_first"), callback_data: "book:date_first"},
        ],
        [
          {text: t(lang, "booking_btn_bike_first"), callback_data: "book:bike_first"},
        ],
      ],
    },
  });
};
