const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {createDraftRental} = require("../services/rentalService");
const {isLaravelMode} = require("../config/runtime");
const {getUserByTelegramId} = require("../services/userService");
const gateway = require("../services/laravelGateway");
const sessionCart = require("../services/sessionCartService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

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
    const user = isLaravelMode()
      ? await getUserByTelegramId(telegramId)
      : await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_not_registered",
        text: t(lang, "not_registered"),
        navigationMode: "replace",
      });
    }

    if (isLaravelMode()) {
      const [vehicle, quote] = await Promise.all([
        gateway.getVehicle(booking.selectedBikeId),
        gateway.quote(booking.selectedBikeId, booking.startDate, booking.endDate),
      ]);
      if (!quote.available) {
        const error = new Error("overlap_conflict");
        error.code = "overlap_conflict";
        throw error;
      }
      sessionCart.add(ctx, booking, vehicle, quote);
    } else {
      await createDraftRental(db, {user, booking});
    }

    return require("./book_draft").showBookingDraft(ctx, {
      lang,
      user,
      backAction: "menu:main",
      navigationMode: "replace",
    });
  } catch (error) {
    if (error.code === "BOT_API_UNAVAILABLE") throw error;
    if (error.code === "bike_not_found") {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_bike_missing",
        text: t(lang, "booking_bike_not_found"),
        navigationMode: "replace",
      });
    }
    if (error.code === "overlap_conflict") {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_bike_unavailable",
        text: t(lang, "booking_bike_busy", {
          name: booking.selectedBikeId || "",
        }),
        replyMarkup: {inline_keyboard: [[{
          text: t(lang, "btn_back"),
          callback_data: "book:back_to_bikes",
        }]]},
        navigationMode: "replace",
      });
    }
    console.error("Ошибка при добавлении аренды:", error);
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_error",
      text: t(lang, "booking_add_error"),
      replyMarkup: {inline_keyboard: [[{
        text: t(lang, "btn_back"),
        callback_data: `book:select_bike:${booking.selectedBikeId}`,
      }]]},
      navigationMode: "replace",
    });
  }
};
