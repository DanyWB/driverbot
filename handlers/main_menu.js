const db = require("../connect");
const {createEmptyBooking} = require("../services/bookingService");
const {t, getCtxLang} = require("../utils/i18n");

async function handleMainMenuAction(ctx, action) {
  const lang = getCtxLang(ctx);

  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }

  if (action === "rent") {
    ctx.session.booking = createEmptyBooking();
    return require("../commands/book")(ctx);
  }

  if (action === "support") {
    return ctx.reply(t(lang, "support_info"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "home"}]],
      },
    });
  }

  if (action === "prices") {
    return ctx.reply(t(lang, "prices_info"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "home"}]],
      },
    });
  }

  if (action === "account") {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (!user) {
      return ctx.reply(t(lang, "not_registered"));
    }

    return ctx.reply(t(lang, "account_info"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "menu_update_name"), callback_data: "update:name"}],
          [{text: t(lang, "menu_update_tel"), callback_data: "update:tel"}],
          [
            {
              text: t(lang, "menu_update_passport"),
              callback_data: "update:passport",
            },
          ],
          [{text: t(lang, "btn_back"), callback_data: "home"}],
        ],
      },
    });
  }

  if (action === "conditions") {
    return ctx.reply(t(lang, "conditions_info"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "home"}]],
      },
    });
  }

  if (action === "about") {
    return ctx.reply(t(lang, "about_info"), {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {text: t(lang, "btn_back"), callback_data: "home"},
            {text: t(lang, "menu_book"), callback_data: "book:start"},
          ],
        ],
      },
    });
  }
}

module.exports = {handleMainMenuAction};
