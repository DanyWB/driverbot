const {getCtxLang, t} = require("../utils/i18n");
const {ensureBooking} = require("../services/bookingService");
const db = require("../connect");
const {showBikeSummary} = require("./book_select_bike");

function getOptionsKeyboard(lang) {
  return {
    inline_keyboard: [
      [
        {text: t(lang, "booking_options_helmets_dec"), callback_data: "book:options:helmets:-"},
        {text: t(lang, "booking_options_helmets_inc"), callback_data: "book:options:helmets:+"},
      ],
      [{text: t(lang, "booking_options_toggle_delivery"), callback_data: "book:options:delivery"}],
      [{text: t(lang, "booking_options_set_address"), callback_data: "book:options:address"}],
      [{text: t(lang, "booking_options_set_notes"), callback_data: "book:options:notes"}],
      [{text: t(lang, "booking_options_back"), callback_data: "book:options:back"}],
    ],
  };
}

function formatOptionsText(booking, lang) {
  const helmets = booking.helmets || 0;
  const delivery = booking.deliveryRequired
    ? t(lang, "booking_options_delivery_on")
    : t(lang, "booking_options_delivery_off");
  const address =
    booking.deliveryAddress && booking.deliveryAddress.trim()
      ? booking.deliveryAddress
      : t(lang, "booking_options_no_address");
  const notes =
    booking.notes && booking.notes.trim()
      ? booking.notes
      : t(lang, "booking_options_no_notes");

  return t(lang, "booking_options_title", {
    helmets,
    delivery,
    address,
    notes,
  });
}

async function sendOptions(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  const booking = ensureBooking(ctx);
  return ctx.editMessageText(formatOptionsText(booking, lang), {
    reply_markup: getOptionsKeyboard(lang),
  });
}

module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data || "";
  const lang = getCtxLang(ctx);
  const booking = ensureBooking(ctx);

  if (action === "book:options") {
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:helmets:+") {
    booking.helmets = Math.min((booking.helmets || 0) + 1, 4);
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:helmets:-") {
    booking.helmets = Math.max((booking.helmets || 0) - 1, 0);
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:delivery") {
    booking.deliveryRequired = !booking.deliveryRequired;
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:address") {
    ctx.session.step = "waiting_for_delivery_address";
    await ctx.answerCallbackQuery();
    return ctx.reply(t(lang, "booking_options_address_prompt"));
  }

  if (action === "book:options:notes") {
    ctx.session.step = "waiting_for_notes";
    await ctx.answerCallbackQuery();
    return ctx.reply(t(lang, "booking_options_notes_prompt"));
  }

  if (action === "book:options:back") {
    const bike = booking.selectedBikeId
      ? await db("bikes").where({id: booking.selectedBikeId}).first()
      : null;
    if (bike) {
      return showBikeSummary(ctx, bike, booking);
    }
    return ctx.reply(t(lang, "booking_choose_bike"), {
      reply_markup: {inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:start"}]]},
    });
  }
};

module.exports.getOptionsKeyboard = getOptionsKeyboard;
module.exports.formatOptionsText = formatOptionsText;
