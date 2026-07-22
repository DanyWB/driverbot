const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml, tHtml} = require("../utils/html");
const gateway = require("../services/laravelGateway");
const {getCart} = require("../services/sessionCartService");
const {preview} = require("../utils/text");
const {vehicleEmoji} = require("../utils/vehicle");

const PAGE_SIZE = 5;

function pageFromAction(action, scope) {
  const match = String(action || "").match(new RegExp(`^rent:${scope}:(\\d+)$`));
  return match ? Number(match[1]) : 0;
}

function appendPaginationRow(keyboard, scope, page, hasNext) {
  const row = [];
  if (page > 0) row.push({text: "←", callback_data: `rent:${scope}:${page - 1}`});
  if (hasNext) row.push({text: "→", callback_data: `rent:${scope}:${page + 1}`});
  if (row.length) keyboard.push(row);
}

function labels(rental) {
  return {
    start: rental.start_at
      ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.start_date).format("DD.MM.YYYY"),
    end: rental.end_at
      ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.end_date).format("DD.MM.YYYY"),
  };
}

function appendRental(text, rental, lang) {
  const period = labels(rental);
  const statusLabel = t(lang, `rent_status_${rental.status}`) || rental.status;
  let result = `${text}${escapeHtml(vehicleEmoji(rental.vehicle))} <b>${escapeHtml(preview(rental.bike_name, 80) || "-")}</b>\n`;
  result += `ID: ${escapeHtml(rental.booking_public_id)}\n`;
  result += `${tHtml(lang, "rent_details_status", {status: statusLabel})}\n`;
  result += `${tHtml(lang, "rent_details_dates", period)}\n`;
  result += `${tHtml(lang, "rent_details_price", {
    price: rental.total_price ?? t(lang, "booking_price_tbd"),
  })}\n`;
  if (rental.helmets_qty || rental.delivery_required) {
    result += `${tHtml(lang, "rent_details_helmets", {helmets: rental.helmets_qty || 0})}\n`;
    result += `${tHtml(lang, "rent_details_delivery", {
      delivery: rental.delivery_required
        ? t(lang, "booking_options_delivery_on")
        : t(lang, "booking_options_delivery_off"),
    })}\n`;
  }
  return `${result}\n`;
}

async function handleLaravelRentMenuAction(ctx, getRentMenuKeyboard) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  if (action === "rent:book") {
    ctx.session.booking = null;
    return require("../commands/book")(ctx);
  }
  if (action === "rent:menu") {
    return ctx.reply(t(lang, "rent_intro"), {
      parse_mode: "HTML",
      reply_markup: getRentMenuKeyboard(lang),
    });
  }
  if (action === "rent:settings") return require("./account_menu").sendAccountMenu(ctx, lang);
  if (action === "rent:support") return require("./support").sendSupportMenu(ctx, lang);
  if (action === "rent:contract") {
    return ctx.reply(t(lang, "conditions_info"), {
      parse_mode: "HTML",
      reply_markup: getRentMenuKeyboard(lang),
    });
  }
  if (action === "rent:payment") {
    return ctx.editMessageText(t(lang, "rent_deposit_empty"), {
      reply_markup: {inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "rent:menu"}]]},
    });
  }

  if (action === "rent:current" || /^rent:current:\d+$/.test(action)) {
    const page = pageFromAction(action, "current");
    const fetched = await gateway.listBookings(ctx.from.id, "current", {
      limit: PAGE_SIZE + 1,
      offset: page * PAGE_SIZE,
    });
    const hasNext = fetched.length > PAGE_SIZE;
    const rentals = fetched.slice(0, PAGE_SIZE);
    const drafts = page === 0 ? getCart(ctx) : [];
    if (!rentals.length && !drafts.length && !ctx.session.booking) {
      return ctx.reply(t(lang, "rent_current_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }

    let text = `<b>${t(lang, "rent_current_title")}</b>\n\n`;
    const keyboard = [];
    if (drafts.length) {
      text += `<b>${t(lang, "rent_current_draft_title")}</b>\n`;
      drafts.forEach((draft) => {
        const period = labels(draft);
        text += `• ${escapeHtml(preview(draft.name, 60))}: ${period.start} - ${period.end}\n`;
      });
      text += `${t(lang, "rent_current_draft_hint")}\n\n`;
      keyboard.push([{text: t(lang, "rent_current_draft_continue_btn"), callback_data: "book:draft"}]);
    }

    rentals.forEach((rental) => {
      text = appendRental(text, rental, lang);
      const row = [{
        text: t(lang, "rent_action_details"),
        callback_data: `rent:details:${rental.booking_public_id}`,
      }];
      if (rental.can_cancel) {
        row.push({
          text: t(lang, "rent_action_cancel"),
          callback_data: `rent:cancel:${rental.booking_public_id}`,
        });
      }
      keyboard.push(row);
    });
    appendPaginationRow(keyboard, "current", page, hasNext);
    keyboard.push([{text: t(lang, "btn_main_menu"), callback_data: "home"}]);
    return ctx.reply(text, {parse_mode: "HTML", reply_markup: {inline_keyboard: keyboard}});
  }

  if (action === "rent:history" || /^rent:history:\d+$/.test(action)) {
    const page = pageFromAction(action, "history");
    const fetched = await gateway.listBookings(ctx.from.id, "history", {
      limit: PAGE_SIZE + 1,
      offset: page * PAGE_SIZE,
    });
    const hasNext = fetched.length > PAGE_SIZE;
    const rentals = fetched.slice(0, PAGE_SIZE);
    if (!rentals.length) {
      return ctx.reply(t(lang, "rent_history_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }
    let text = `<b>${t(lang, "rent_btn_history")}</b>\n\n`;
    const keyboard = [];
    rentals.forEach((rental) => {
      text = appendRental(text, rental, lang);
      keyboard.push([{
        text: t(lang, "rent_action_details"),
        callback_data: `rent:details:${rental.booking_public_id}`,
      }]);
    });
    appendPaginationRow(keyboard, "history", page, hasNext);
    keyboard.push([{text: t(lang, "btn_main_menu"), callback_data: "home"}]);
    return ctx.reply(text, {parse_mode: "HTML", reply_markup: {inline_keyboard: keyboard}});
  }
}

module.exports = {handleLaravelRentMenuAction};
