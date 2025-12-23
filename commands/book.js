const {createEmptyBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  ctx.session.booking = createEmptyBooking();

  const lang = getCtxLang(ctx);
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
