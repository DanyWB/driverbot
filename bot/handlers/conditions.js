const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

function conditionsKeyboard(lang, accepted) {
  return {
    inline_keyboard: [
      [
        {
          text: accepted
            ? t(lang, "conditions_accepted_label")
            : t(lang, "conditions_accept_btn"),
          callback_data: accepted ? "noop" : "conditions:accept",
        },
      ],
      [
        {
          text: t(lang, "conditions_book_btn"),
          callback_data: "book:start",
        },
      ],
      [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
    ],
  };
}

async function sendConditions(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  let accepted = Boolean(ctx.session?.acceptTerms);
  if (!accepted) {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (user) {
      const hasAccepted = await db("rentals")
        .where({user_id: user.id, status: "process", accept_terms: true})
        .first();
      accepted = Boolean(hasAccepted);
      if (accepted) {
        ctx.session.acceptTerms = true;
      }
    }
  }
  return ctx.reply(t(lang, "conditions_info"), {
    parse_mode: "HTML",
    reply_markup: conditionsKeyboard(lang, accepted),
  });
}

async function handleConditionsAction(ctx) {
  const action = ctx.callbackQuery?.data;
  const lang = getCtxLang(ctx);

  if (action === "noop") {
    return ctx.answerCallbackQuery();
  }

  if (action === "conditions:accept") {
    if (ctx.session?.acceptTerms) {
      return ctx.answerCallbackQuery(t(lang, "conditions_accepted_label"));
    }

    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (user) {
      await db("rentals")
        .where({user_id: user.id, status: "process"})
        .update({accept_terms: true});
    }
    ctx.session.acceptTerms = true;
    return ctx.editMessageReplyMarkup({
      reply_markup: conditionsKeyboard(lang, true),
    });
  }

  if (action === "conditions:open") {
    return sendConditions(ctx, lang);
  }

  if (action === "conditions:accept_toggle") {
    ctx.session.acceptTerms = true;
    const current = ctx.callbackQuery?.message?.reply_markup?.inline_keyboard || [];
    const updated = current.map((row) =>
      row.map((btn) => {
        if (btn.callback_data === "conditions:accept_toggle") {
          return {
            text: t(lang, "conditions_accepted_label"),
            callback_data: "noop",
          };
        }
        return btn;
      })
    );
    return ctx.editMessageReplyMarkup({reply_markup: {inline_keyboard: updated}});
  }
}

module.exports = {sendConditions, handleConditionsAction};
