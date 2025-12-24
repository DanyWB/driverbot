const db = require("../connect");
const dayjs = require("dayjs");
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
    !booking.startTime ||
    !booking.endDate ||
    !booking.endTime ||
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

    const rentals = await db("rentals")
      .join("bikes", "rentals.bike_id", "bikes.id")
      .where("user_id", user.id)
      .andWhere("status", "process")
      .select(
        "rentals.id",
        "bikes.name",
        "rentals.start_date",
        "rentals.end_date",
        "rentals.total_price",
        "rentals.helmets_qty",
        "rentals.delivery_required",
        "rentals.delivery_address",
        "rentals.comment"
      );

    let text = t(lang, "booking_current_title");

    for (const rental of rentals) {
      const days =
        dayjs(rental.end_date).diff(dayjs(rental.start_date), "day") + 1;
      text += t(lang, "booking_item", {
        name: rental.name,
        start: dayjs(rental.start_date).format("DD.MM.YYYY"),
        end: dayjs(rental.end_date).format("DD.MM.YYYY"),
        days,
        days_label: t(lang, "days_label"),
        price: rental.total_price || t(lang, "booking_price_tbd"),
      });
      if (rental.helmets_qty || rental.delivery_required || rental.delivery_address || rental.comment) {
        text += `🪖 ${rental.helmets_qty || 0}; 🚚 ${
          rental.delivery_required
            ? t(lang, "booking_options_delivery_on")
            : t(lang, "booking_options_delivery_off")
        }`;
        if (rental.delivery_address) {
          text += `; 🏠 ${rental.delivery_address}`;
        }
        if (rental.comment) {
          text += `\n✏️ ${rental.comment}`;
        }
        text += "\n\n";
      }
    }

    if (ctx.session.acceptTerms) {
      text += `\n${t(lang, "conditions_accepted_label")}`;
    } else {
      text += `\n${t(lang, "conditions_accept_required")}`;
    }

    await ctx.editMessageText(text, {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: t(lang, "booking_confirm_btn"),
              callback_data: "book:confirm_rental",
            },
          ],
          [
            {
              text: ctx.session.acceptTerms
                ? t(lang, "conditions_accepted_label")
                : t(lang, "conditions_accept_btn"),
              callback_data: ctx.session.acceptTerms
                ? "noop"
                : "conditions:accept_toggle",
            },
          ],
          [
            {text: t(lang, "booking_add_bike_btn"), callback_data: "book:start"},
            {text: t(lang, "booking_delete_bike_btn"), callback_data: "book:delete_bike"},
          ],
          [
            {text: t(lang, "booking_reset_btn"), callback_data: "book:reset_rental"},
            {text: t(lang, "booking_comment_btn"), callback_data: "book:comment"},
          ],
          [{text: t(lang, "btn_back"), callback_data: "home"}],
        ],
      },
    });
  } catch (error) {
    if (error.message === "overlap_conflict") {
      return ctx.reply(t(lang, "booking_bike_busy", {name: booking.selectedBikeId || ""}));
    }
    console.error("Ошибка при добавлении аренды:", error);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
