const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml, tHtml} = require("../utils/html");
const {getBooking} = require("../services/laravelGateway");
const {preview} = require("../utils/text");

module.exports = async (ctx) => {
  const publicId = (ctx.callbackQuery?.data || "").split(":").slice(2).join(":");
  const lang = getCtxLang(ctx);
  const rental = await getBooking(ctx.from.id, publicId);
  if (!rental) return ctx.reply(t(lang, "rent_current_empty"));

  const statusLabel = t(lang, `rent_status_${rental.status}`) || rental.status;
  const startLabel = rental.start_at
    ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
    : dayjs(rental.start_date).format("DD.MM.YYYY");
  const endLabel = rental.end_at
    ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
    : dayjs(rental.end_date).format("DD.MM.YYYY");
  const lines = [
    `<b>${t(lang, "rent_details_title")}</b>`,
    `ID: ${escapeHtml(rental.booking_public_id)}`,
    tHtml(lang, "rent_details_status", {status: statusLabel}),
    tHtml(lang, "rent_details_dates", {start: startLabel, end: endLabel}),
    tHtml(lang, "rent_details_model", {model: preview(rental.bike_name, 80)}),
    rental.bike_desc ? tHtml(lang, "rent_details_notes", {notes: preview(rental.bike_desc)}) : "",
    tHtml(lang, "rent_details_helmets", {helmets: rental.helmets_qty || 0}),
    tHtml(lang, "rent_details_delivery", {
      delivery: rental.delivery_required
        ? t(lang, "booking_options_delivery_on")
        : t(lang, "booking_options_delivery_off"),
    }),
    rental.delivery_address
      ? tHtml(lang, "rent_details_address", {address: preview(rental.delivery_address)})
      : "",
    rental.comment ? tHtml(lang, "rent_details_notes", {notes: preview(rental.comment)}) : "",
    tHtml(lang, "rent_details_price", {
      price: rental.total_price ?? t(lang, "booking_price_tbd"),
    }),
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
  inlineKeyboard.push([{text: t(lang, "rent_action_back"), callback_data: "rent:current"}]);
  return ctx.editMessageText(lines.join("\n"), {
    parse_mode: "HTML",
    reply_markup: {inline_keyboard: inlineKeyboard},
  });
};
