const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const gateway = require("../services/laravelGateway");
const {botScreenRenderer} = require("../services/botScreenRenderer");
const {preview} = require("../utils/text");
const {
  cancellationReason,
  createdLabel,
  displayPeriod,
  localizedCategory,
  localizedStatus,
  rentalDays,
  rentalVehicleName,
} = require("../utils/rentalPresentation");

const HISTORY_STATUSES = new Set([
  "cancelled",
  "cancelled_by_client",
  "completed",
  "expired",
  "no_show",
  "finished",
  "returned",
]);

function inferDetailsOrigin(ctx, rental) {
  if (ctx.session?.currentScreen === "rent_history") return "history";
  if (ctx.session?.currentScreen === "rent_current") return "current";
  if (["history", "current"].includes(ctx.session?.rentDetailsOrigin)) {
    return ctx.session.rentDetailsOrigin;
  }
  return HISTORY_STATUSES.has(rental?.status) ? "history" : "current";
}

function buildDetailsPayload(rental, lang, {origin = "current", page = 0} = {}) {
  const period = displayPeriod(rental);
  const days = rentalDays(rental);
  const created = createdLabel(rental);
  const reason = cancellationReason(rental);
  const lines = [
    `<b>${t(lang, "rent_details_title")}</b>`,
    tHtml(lang, "rent_details_status", {status: localizedStatus(rental, lang)}),
    tHtml(lang, "rent_details_model", {model: rentalVehicleName(rental)}),
    tHtml(lang, "rent_details_category", {
      category: localizedCategory(rental, lang),
    }),
    tHtml(lang, "rent_details_dates", period),
    days === null ? "" : tHtml(lang, "rent_details_days", {days}),
    tHtml(lang, "rent_details_price", {
      price: rental.total_price ?? t(lang, "booking_price_tbd"),
    }),
    created ? tHtml(lang, "rent_details_created", {created}) : "",
    reason ? tHtml(lang, "rent_details_reason", {reason: preview(reason, 300)}) : "",
    rental.helmets_qty
      ? tHtml(lang, "rent_details_helmets", {helmets: rental.helmets_qty})
      : "",
    rental.delivery_required
      ? tHtml(lang, "rent_details_delivery", {
          delivery: t(lang, "booking_options_delivery_on"),
        })
      : "",
    rental.delivery_address
      ? tHtml(lang, "rent_details_address", {
          address: preview(rental.delivery_address, 300),
        })
      : "",
    rental.comment
      ? tHtml(lang, "rent_details_notes", {notes: preview(rental.comment, 300)})
      : "",
  ].filter(Boolean);

  if (rental.cancellation?.requires_manager) {
    lines.push(rental.cancellation.manager_telegram
      ? tHtml(lang, "rent_cancel_manager_required", {
          manager: rental.cancellation.manager_telegram,
        })
      : t(lang, "rent_cancel_manager_contact_missing"));
  }

  const inlineKeyboard = [];
  if (rental.can_cancel) {
    inlineKeyboard.push([{
      text: t(lang, "rent_action_cancel"),
      callback_data: `rent:cancel:${rental.booking_public_id}`,
    }]);
  }
  inlineKeyboard.push([{
    text: t(lang, "rent_action_back"),
    callback_data: `rent:${origin}:${Math.max(0, Number(page) || 0)}`,
  }]);
  inlineKeyboard.push([{
    text: t(lang, "btn_main_menu"),
    callback_data: "menu:main",
  }]);

  return {text: lines.join("\n"), replyMarkup: {inline_keyboard: inlineKeyboard}};
}

async function handleLaravelRentDetailsWithDeps(ctx, dependencies = {}) {
  const publicId = (ctx.callbackQuery?.data || "").split(":").slice(2).join(":");
  const lang = getCtxLang(ctx);
  const rental = await (dependencies.getBooking || gateway.getBooking)(
    ctx.from.id,
    publicId
  );
  const renderer = dependencies.renderer || botScreenRenderer;
  if (!rental) {
    return renderer.renderText(ctx, {
      screen: "rent_details_missing",
      text: t(lang, "rent_current_empty"),
      replyMarkup: {
        inline_keyboard: [[
          {text: t(lang, "btn_back"), callback_data: "rent:menu"},
        ]],
      },
    });
  }

  const origin = inferDetailsOrigin(ctx, rental);
  const page = origin === "history"
    ? Number(ctx.session?.historyPage) || 0
    : Number(ctx.session?.currentRentalsPage) || 0;
  ctx.session.rentDetailsOrigin = origin;
  const payload = buildDetailsPayload(rental, lang, {origin, page});
  return renderer.renderText(ctx, {
    screen: "rent_details",
    text: payload.text,
    parseMode: "HTML",
    replyMarkup: payload.replyMarkup,
    returnContext: {origin, page},
  });
}

async function handleLaravelRentDetails(ctx) {
  return handleLaravelRentDetailsWithDeps(ctx);
}

module.exports = handleLaravelRentDetails;
module.exports.HISTORY_STATUSES = HISTORY_STATUSES;
module.exports.buildDetailsPayload = buildDetailsPayload;
module.exports.handleLaravelRentDetailsWithDeps = handleLaravelRentDetailsWithDeps;
module.exports.inferDetailsOrigin = inferDetailsOrigin;
