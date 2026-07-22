const dayjs = require("dayjs");
const {getCtxLang, t} = require("../utils/i18n");
const {ensureBooking} = require("../services/bookingService");
const {getTimeKeyboard} = require("../utils/timeKeyboard");
const {makeDateTime} = require("../utils/timeSlots");
const db = require("../connect");
const {showBikeSummary} = require("./book_select_bike");
const {isLaravelMode} = require("../config/runtime");
const {applyOptions, loadOptionsIntoBooking} = require("../services/sessionCartService");
const {getVehicleById} = require("../services/vehicleService");

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
      [{text: t(lang, "booking_options_time_start"), callback_data: "book:options:time_start"}],
      [{text: t(lang, "booking_options_time_end"), callback_data: "book:options:time_end"}],
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
  const startTime = booking.startTime || t(lang, "booking_options_time_not_set");
  const endTime = booking.endTime || t(lang, "booking_options_time_not_set");

  return t(lang, "booking_options_title", {
    helmets,
    delivery,
    address,
    notes,
    start_time: startTime,
    end_time: endTime,
  });
}

async function sendOptions(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  const booking = ensureBooking(ctx);
  return ctx.editMessageText(formatOptionsText(booking, lang), {
    reply_markup: getOptionsKeyboard(lang),
  });
}

async function persistProcessOptions(ctx, booking) {
  if (isLaravelMode()) {
    applyOptions(ctx, booking);
    return;
  }
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) return;

  const rentals = await db("rentals")
    .where({user_id: user.id, status: "process"})
    .select("id", "start_date", "end_date");
  if (!rentals.length) return;

  const baseUpdate = {
    helmets_qty: booking.helmets || 0,
    delivery_required: Boolean(booking.deliveryRequired),
    delivery_address: booking.deliveryAddress || null,
    comment: booking.notes || null,
  };

  for (const rental of rentals) {
    const updates = {...baseUpdate};
    if (booking.startTime) {
      const startAt = makeDateTime(rental.start_date, booking.startTime);
      if (startAt && startAt.isValid()) {
        updates.start_at = startAt.toISOString();
      }
    }
    if (booking.endTime) {
      const endAt = makeDateTime(rental.end_date, booking.endTime);
      if (endAt && endAt.isValid()) {
        updates.end_at = endAt.toISOString();
      }
    }
    await db("rentals").where({id: rental.id}).update(updates);
  }
}

async function loadProcessOptions(ctx, lang) {
  if (isLaravelMode()) {
    const booking = ensureBooking(ctx);
    const item = loadOptionsIntoBooking(ctx, booking);
    if (!item) return ctx.answerCallbackQuery(t(lang, "booking_no_bikes_in_process"));
    ctx.session.optionsScope = "process";
    return sendOptions(ctx, lang);
  }
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) {
    return ctx.reply(t(lang, "not_registered"));
  }

  const rental = await db("rentals")
    .where({user_id: user.id, status: "process"})
    .orderBy("id", "desc")
    .first();

  if (!rental) {
    return ctx.answerCallbackQuery(t(lang, "booking_no_bikes_in_process"));
  }

  const booking = ensureBooking(ctx);
  booking.startDate = rental.start_date;
  booking.endDate = rental.end_date;
  booking.startTime = rental.start_at
    ? dayjs(rental.start_at).format("HH:mm")
    : null;
  booking.endTime = rental.end_at ? dayjs(rental.end_at).format("HH:mm") : null;
  booking.helmets = rental.helmets_qty || 0;
  booking.deliveryRequired = Boolean(rental.delivery_required);
  booking.deliveryAddress = rental.delivery_address || null;
  booking.notes = rental.comment || null;
  booking.timeSource = null;
  ctx.session.optionsScope = "process";

  return sendOptions(ctx, lang);
}

module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data || "";
  const lang = getCtxLang(ctx);
  const booking = ensureBooking(ctx);

  if (action === "book:options:process") {
    return loadProcessOptions(ctx, lang);
  }

  if (action === "book:options") {
    booking.timeSource = null;
    ctx.session.optionsScope = null;
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:helmets:+") {
    booking.helmets = Math.min((booking.helmets || 0) + 1, 4);
    if (ctx.session.optionsScope === "process") {
      await persistProcessOptions(ctx, booking);
    }
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:helmets:-") {
    booking.helmets = Math.max((booking.helmets || 0) - 1, 0);
    if (ctx.session.optionsScope === "process") {
      await persistProcessOptions(ctx, booking);
    }
    return sendOptions(ctx, lang);
  }

  if (action === "book:options:delivery") {
    booking.deliveryRequired = !booking.deliveryRequired;
    if (ctx.session.optionsScope === "process") {
      await persistProcessOptions(ctx, booking);
    }
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

  if (action === "book:options:time_start") {
    if (!booking.startDate) {
      return ctx.answerCallbackQuery({
        text: t(lang, "booking_dates_not_selected"),
        show_alert: true,
      });
    }
    booking.timeSource = "options";
    const keyboard = getTimeKeyboard("start");
    const backAction =
      ctx.session.optionsScope === "process" ? "book:options:process" : "book:options";
    keyboard.inline_keyboard.push([{text: t(lang, "btn_back"), callback_data: backAction}]);
    return ctx.editMessageText(t(lang, "booking_choose_start_time"), {
      reply_markup: keyboard,
    });
  }

  if (action === "book:options:time_end") {
    if (!booking.endDate) {
      return ctx.answerCallbackQuery({
        text: t(lang, "booking_dates_not_selected"),
        show_alert: true,
      });
    }
    booking.timeSource = "options";
    const keyboard = getTimeKeyboard("end");
    const backAction =
      ctx.session.optionsScope === "process" ? "book:options:process" : "book:options";
    keyboard.inline_keyboard.push([{text: t(lang, "btn_back"), callback_data: backAction}]);
    return ctx.editMessageText(t(lang, "booking_choose_end_time"), {
      reply_markup: keyboard,
    });
  }

  if (action === "book:options:back") {
    booking.timeSource = null;
    if (ctx.session.optionsScope === "process") {
      ctx.session.optionsScope = null;
      const {buildDraftMenuPayload} = require("./booking_draft_menu");
      const payload = await buildDraftMenuPayload(ctx, {
        lang,
        backAction: "rent:current",
      });
      if (payload?.error) {
        return ctx.reply(payload.error);
      }
      if (payload?.empty) {
        return ctx.reply(t(lang, "booking_no_bikes_in_process"));
      }
      return ctx.editMessageText(payload.text, {
        parse_mode: "HTML",
        reply_markup: payload.reply_markup,
      });
    }
    const bike = booking.selectedBikeId
      ? await getVehicleById(db, booking.selectedBikeId)
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
module.exports.persistProcessOptions = persistProcessOptions;
