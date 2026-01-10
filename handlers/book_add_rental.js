const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {generateBookingId} = require("../utils/bookingId");
const {makeDateTime} = require("../utils/timeSlots");
const {hasOverlap} = require("../utils/overlap");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  if (
    !booking ||
    !booking.startDate ||
    !booking.endDate ||
    !booking.selectedBikeId ||
    !booking.totalPrice
  ) {
    return ctx.answerCallbackQuery(t(lang, "booking_not_enough_data"));
  }

  try {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return ctx.reply(t(lang, "not_registered"));
    }

    const startAt = makeDateTime(booking.startDate, booking.startTime);
    const endAt = makeDateTime(booking.endDate, booking.endTime);

    const existingRental = await db("rentals")
      .where({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        status: "process",
      })
      .first();

    if (!existingRental) {
      await db.transaction(async (trx) => {
        const overlap = await hasOverlap(
          trx,
          booking.selectedBikeId,
          startAt ? startAt.toISOString() : null,
          endAt ? endAt.toISOString() : null,
          booking.startDate,
          booking.endDate
        );
        if (overlap) {
          throw new Error("overlap_conflict");
        }

        await trx("rentals").insert({
          user_id: user.id,
          bike_id: booking.selectedBikeId,
          start_date: booking.startDate,
          end_date: booking.endDate,
          start_at: startAt ? startAt.toISOString() : null,
          end_at: endAt ? endAt.toISOString() : null,
          total_price: booking.totalPrice,
          status: "process",
          docs_missing: !user.passport_photo_file_id && !user.meta?.passport_number,
          helmets_qty: booking.helmets || 0,
          delivery_required: Boolean(booking.deliveryRequired),
          delivery_address: booking.deliveryAddress || null,
          comment: booking.notes || null,
          booking_public_id: generateBookingId(),
          deposit_required: booking.deposit || null,
        });
      });
    }

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
    if (error.message === "overlap_conflict") {
      return ctx.reply(t(lang, "booking_bike_busy", {name: booking.selectedBikeId || ""}));
    }
    console.error("Ошибка при добавлении аренды:", error);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
