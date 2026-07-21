const {createEmptyBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");
const {sendAccountMenu} = require("./account_menu");
const {sendConditions} = require("./conditions");
const {sendPricesMenu} = require("./prices");
const {sendSupportMenu} = require("./support");

async function handleMainMenuAction(ctx, action) {
  const lang = getCtxLang(ctx);

  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }

  if (action === "rent") {
    ctx.session.booking = createEmptyBooking();
    const {sendRentMenu} = require("./rent_menu");
    return sendRentMenu(ctx, lang);
  }

  if (action === "support") {
    return sendSupportMenu(ctx, lang);
  }

  if (action === "prices") {
    return sendPricesMenu(ctx, lang);
  }

  if (action === "account") {
    return sendAccountMenu(ctx, lang);
  }

  if (action === "conditions") {
    return sendConditions(ctx, lang);
  }

  if (action === "about") {
    return ctx.reply(t(lang, "about_info"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {text: t(lang, "btn_main_menu"), callback_data: "home"},
            {text: t(lang, "menu_book"), callback_data: "book:start"},
          ],
        ],
      },
    });
  }
}

module.exports = {handleMainMenuAction};
