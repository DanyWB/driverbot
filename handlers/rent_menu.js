const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {sendSupportMenu} = require("./support");

function getRentMenuKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
      [
        {text: t(lang, "rent_btn_current"), callback_data: "rent:current"},
        {text: t(lang, "rent_btn_history"), callback_data: "rent:history"},
      ],
      [
        {text: t(lang, "rent_btn_contract"), callback_data: "rent:contract"},
        {text: t(lang, "rent_btn_payment"), callback_data: "rent:payment"},
      ],
      [
        {text: t(lang, "rent_btn_settings"), callback_data: "rent:settings"},
        {text: t(lang, "rent_btn_support"), callback_data: "rent:support"},
      ],
      [{text: t(lang, "btn_back"), callback_data: "home"}],
    ],
  };
}

async function sendRentMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  return ctx.reply(t(lang, "rent_intro"), {
    parse_mode: "HTML",
    reply_markup: getRentMenuKeyboard(lang),
  });
}

async function handleRentMenuAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  if (action === "rent:book") {
    ctx.session.booking = null;
    return require("../commands/book")(ctx);
  }

  if (action === "rent:current") {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (!user) return ctx.reply(t(lang, "not_registered"));
    const rentals = await db("rentals")
      .where("user_id", user.id)
      .andWhere((qb) =>
        qb
          .where("status", "active")
          .orWhere("status", "pending")
          .orWhere("status", "process")
          .orWhere("status", "ready")
      );

    if (!rentals.length) {
      return ctx.reply(t(lang, "rent_current_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }

    if (!rentals.length) {
      return ctx.reply(t(lang, "rent_current_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }

    const keyboard = [];
    let text = t(lang, "rent_btn_current") + ":\n\n";
    rentals.forEach((r) => {
      const label = `• ${t(lang, "rent_action_details")} ID ${
        r.booking_public_id || r.id
      }: ${r.start_date || r.start_at || "-"} - ${r.end_date || r.end_at || "-"} (${r.status})`;
      text += `${label}\n`;
      keyboard.push([
        {text: t(lang, "rent_action_details"), callback_data: `rent:details:${r.id}`},
        {text: t(lang, "rent_action_cancel"), callback_data: `rent:cancel:${r.id}`},
      ]);
    });
    keyboard.push([{text: t(lang, "btn_back"), callback_data: "home"}]);

    return ctx.reply(text, {reply_markup: {inline_keyboard: keyboard}});
  }

  if (action === "rent:history") {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (!user) return ctx.reply(t(lang, "not_registered"));
    const rentals = await db("rentals")
      .where("user_id", user.id)
      .whereIn("status", ["cancelled", "completed", "finished", "expired", "ready", "returned"]);

    if (!rentals.length) {
    return ctx.reply(t(lang, "rent_history_empty"), {
      reply_markup: getRentMenuKeyboard(lang),
    });
  }

    let text = t(lang, "rent_btn_history") + ":\n\n";
    const keyboard = [];
    rentals.forEach((r) => {
      text += `• ${t(lang, "rent_history_details")} ID ${r.booking_public_id || r.id}: ${
        r.start_date || "-"
      } - ${r.end_date || "-"} (${r.status})\n`;
      keyboard.push([{text: t(lang, "rent_action_details"), callback_data: `rent:details:${r.id}`}]);
    });
    keyboard.push([{text: t(lang, "btn_back"), callback_data: "home"}]);

    return ctx.reply(text, {reply_markup: {inline_keyboard: keyboard}});
  }

  if (action === "rent:contract") {
    return ctx.reply(t(lang, "conditions_info"), {
      parse_mode: "HTML",
      reply_markup: getRentMenuKeyboard(lang),
    });
  }

  if (action === "rent:payment") {
    return ctx.reply(t(lang, "prices_info"), {
      parse_mode: "HTML",
      reply_markup: getRentMenuKeyboard(lang),
    });
  }

  if (action === "rent:settings") {
    // Redirect to account update options
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

  if (action === "rent:support") {
    return sendSupportMenu(ctx, lang);
  }
}

module.exports = {sendRentMenu, handleRentMenuAction};
