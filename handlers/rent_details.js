const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":"); // rent:details:ID
  const rentalId = Number(parts[2]);
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) return ctx.reply(t(lang, "not_registered"));

  const rental = await db("rentals")
    .join("bikes", "rentals.bike_id", "bikes.id")
    .where("rentals.id", rentalId)
    .andWhere("rentals.user_id", user.id)
    .select(
      "rentals.*",
      "bikes.name as bike_name",
      "bikes.description as bike_desc"
    )
    .first();

  if (!rental) {
    return ctx.reply(t(lang, "rent_current_empty"));
  }

  if (rental.status === "process") {
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

  const statusKey = `rent_status_${rental.status}`;
  const statusLabel = t(lang, statusKey) || rental.status;
  const startLabel = rental.start_at
    ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
    : dayjs(rental.start_date).format("DD.MM.YYYY");
  const endLabel = rental.end_at
    ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
    : dayjs(rental.end_date).format("DD.MM.YYYY");

  const text = [
    `<b>${t(lang, "rent_details_title")}</b>`,
    `ID: ${rental.booking_public_id || rental.id}`,
    `${t(lang, "rent_details_status", {status: statusLabel})}`,
    `${t(lang, "rent_details_dates", {
      start: startLabel,
      end: endLabel,
    })}`,
    `${t(lang, "rent_details_model", {model: rental.bike_name})}`,
    rental.bike_desc
      ? `${t(lang, "rent_details_notes", {notes: rental.bike_desc})}`
      : "",
    `${t(lang, "rent_details_helmets", {helmets: rental.helmets_qty || 0})}`,
    `${t(lang, "rent_details_delivery", {
      delivery: rental.delivery_required
        ? t(lang, "booking_options_delivery_on")
        : t(lang, "booking_options_delivery_off"),
    })}`,
    rental.delivery_address
      ? `${t(lang, "rent_details_address", {address: rental.delivery_address})}`
      : "",
    rental.comment
      ? `${t(lang, "rent_details_notes", {notes: rental.comment})}`
      : "",
    `${t(lang, "rent_details_price", {
      price: rental.total_price || t(lang, "booking_price_tbd"),
    })}`,
    `${t(lang, "rent_details_deposit", {
      deposit: rental.deposit_required || "-",
    })}`,
    rental.contract_file_id
      ? `${t(lang, "rent_details_contract", {contract: rental.contract_file_id})}`
      : "",
  ]
    .filter(Boolean)
    .join("\n");

  const inline_keyboard = [];
  if (["process", "pending", "active", "ready", "approved"].includes(rental.status)) {
    inline_keyboard.push([
      {text: t(lang, "rent_action_cancel"), callback_data: `rent:cancel:${rentalId}`},
    ]);
  }
  inline_keyboard.push([{text: t(lang, "rent_action_back"), callback_data: "rent:current"}]);

  return ctx.editMessageText(text, {parse_mode: "HTML", reply_markup: {inline_keyboard}});
};
