const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml, tHtml} = require("../utils/html");
const {isLaravelMode} = require("../config/runtime");
const {getUserByTelegramId} = require("../services/userService");
const {getCart} = require("../services/sessionCartService");
const {preview} = require("../utils/text");
const {vehicleEmoji} = require("../utils/vehicle");

async function buildDraftMenuPayload(ctx, options = {}) {
  const lang = options.lang || getCtxLang(ctx);
  const backAction = options.backAction || "home";
  const backText =
    options.backText ||
    (backAction === "rent:current"
      ? t(lang, "rent_current_back_btn")
      : backAction === "home"
      ? t(lang, "btn_main_menu")
      : t(lang, "btn_back"));
  ctx.session.commentReturn =
    backAction === "rent:current" ? "rent:current" : "book:draft";

  const user = options.user || (isLaravelMode()
    ? await getUserByTelegramId(ctx.from.id)
    : await db("users").where({telegram_id: ctx.from.id}).first());
  if (!user) {
    return {error: t(lang, "not_registered")};
  }

  const rentals = isLaravelMode()
    ? getCart(ctx)
    : await db("rentals")
        .join("bikes", "rentals.bike_id", "bikes.id")
        .where("rentals.user_id", user.id)
        .andWhere("rentals.status", "process")
        .select(
          "rentals.id",
          "rentals.accept_terms",
          "rentals.start_date",
          "rentals.end_date",
          "rentals.start_at",
          "rentals.end_at",
          "rentals.total_price",
          "rentals.helmets_qty",
          "rentals.delivery_required",
          "rentals.delivery_address",
          "rentals.comment",
          "bikes.name"
        );

  if (!rentals.length) {
    return {empty: true};
  }

  const accepted = isLaravelMode()
    ? Boolean(ctx.session.acceptTerms && ctx.session.acceptedTermsVersion)
    : Boolean(ctx.session.acceptTerms) ||
      rentals.some((rental) => rental.accept_terms === true);

  if (accepted) {
    ctx.session.acceptTerms = true;
  }

  let text = t(lang, "booking_current_title");

  for (const rental of rentals) {
    const days =
      dayjs(rental.end_date).diff(dayjs(rental.start_date), "day") + 1;
    const startLabel = rental.start_at
      ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.start_date).format("DD.MM.YYYY");
    const endLabel = rental.end_at
      ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.end_date).format("DD.MM.YYYY");

    text += tHtml(lang, "booking_item", {
      emoji: vehicleEmoji(rental.vehicle || rental),
      name: preview(rental.name, 60),
      start: startLabel,
      end: endLabel,
      days,
      days_label: t(lang, "days_label"),
      price: rental.total_price || t(lang, "booking_price_tbd"),
    });
  }

  const rentalOptions = rentals[0];
  if (
    rentalOptions.helmets_qty ||
    rentalOptions.delivery_required ||
    rentalOptions.delivery_address ||
    rentalOptions.comment
  ) {
    text += `🪖 ${rentalOptions.helmets_qty || 0}; 🚚 ${
      rentalOptions.delivery_required
        ? t(lang, "booking_options_delivery_on")
        : t(lang, "booking_options_delivery_off")
    }`;
    if (rentalOptions.delivery_address) {
      text += `; 🏠 ${escapeHtml(preview(rentalOptions.delivery_address, 120))}`;
    }
    if (rentalOptions.comment) {
      text += `\n✏️ ${escapeHtml(preview(rentalOptions.comment, 120))}`;
    }
    text += "\n\n";
  }

  if (accepted) {
    text += `\n${t(lang, "conditions_accepted_label")}`;
  } else {
    text += `\n${t(lang, "conditions_accept_required")}`;
  }

  const acceptButton = accepted
    ? {text: t(lang, "conditions_accepted_label"), callback_data: "noop"}
    : {text: t(lang, "conditions_accept_btn"), callback_data: "conditions:accept_toggle"};

  const keyboard = [
    [
      {
        text: t(lang, "booking_confirm_btn"),
        callback_data: "book:confirm_rental",
      },
    ],
    [acceptButton],
    [
      {
        text: t(lang, "booking_comment_btn"),
        callback_data: "book:options:process",
      },
    ],
    [
      {text: t(lang, "booking_add_bike_btn"), callback_data: "book:start"},
      {text: t(lang, "booking_delete_bike_btn"), callback_data: "book:delete_bike"},
    ],
    [{text: t(lang, "booking_reset_btn"), callback_data: "book:reset_rental"}],
    [{text: backText, callback_data: backAction}],
  ];

  return {text, reply_markup: {inline_keyboard: keyboard}};
}

module.exports = {buildDraftMenuPayload};
