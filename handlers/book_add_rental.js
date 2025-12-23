const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");

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

    const existingRental = await db("rentals")
      .where({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        status: "process",
      })
      .first();

    if (!existingRental) {
      await db("rentals").insert({
        user_id: user.id,
        bike_id: booking.selectedBikeId,
        start_date: booking.startDate,
        end_date: booking.endDate,
        total_price: booking.totalPrice,
        status: "process",
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
        "rentals.total_price"
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
        price: rental.total_price,
      });
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
          [{text: t(lang, "booking_add_bike_btn"), callback_data: "book:start"}],
          [
            {text: t(lang, "booking_delete_bike_btn"), callback_data: "book:delete_bike"},
          ],
          [{text: t(lang, "booking_reset_btn"), callback_data: "book:reset_rental"}],
          [{text: t(lang, "booking_comment_btn"), callback_data: "book:comment"}],
        ],
      },
    });
  } catch (error) {
    console.error("Ошибка при добавлении аренды:", error);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
