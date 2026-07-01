const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {createDraftRental} = require("../services/rentalService");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  if (
    !booking ||
    !booking.startDate ||
    !booking.endDate ||
    !booking.selectedBikeId ||
    (!booking.priceUnknown && booking.totalPrice == null)
  ) {
    return ctx.answerCallbackQuery(t(lang, "booking_not_enough_data"));
  }

  try {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return ctx.reply(t(lang, "not_registered"));
    }

    await createDraftRental(db, {user, booking});

    const {buildDraftMenuPayload} = require("./booking_draft_menu");
    const payload = await buildDraftMenuPayload(ctx, {lang, user, backAction: "home"});

    if (payload?.error) {
      return ctx.reply(payload.error);
    }
    if (payload?.empty) {
      return ctx.reply(t(lang, "booking_no_bikes_in_process"));
    }

    await ctx.editMessageText(payload.text, {
      parse_mode: "HTML",
      reply_markup: payload.reply_markup,
    });
  } catch (error) {
    if (error.code === "bike_not_found") {
      return ctx.reply(t(lang, "booking_bike_not_found"));
    }
    if (error.code === "overlap_conflict") {
      return ctx.reply(t(lang, "booking_bike_busy", {name: booking.selectedBikeId || ""}));
    }
    console.error("Ошибка при добавлении аренды:", error);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
