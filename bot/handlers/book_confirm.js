const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const {
  confirmDraftRentals,
  getDraftRentalsForUser,
} = require("../services/rentalService");
const {isLaravelMode} = require("../config/runtime");
const {ensureBooking} = require("../services/bookingService");
const {getUserByTelegramId} = require("../services/userService");
const gateway = require("../services/laravelGateway");
const sessionCart = require("../services/sessionCartService");
const {botScreenRenderer} = require("../services/botScreenRenderer");
const {
  clearActiveUiMessage,
  getActiveUiMessage,
} = require("../utils/navigationState");

async function sendConfirmationReceipt(ctx, lang) {
  try {
    await ctx.answerCallbackQuery();
  } catch (error) {
    // Callback answers are best effort; the booking has already been created.
  }

  const active = getActiveUiMessage(ctx.session);
  const chatId = ctx.chat?.id || ctx.callbackQuery?.message?.chat?.id;
  if (active && chatId) {
    try {
      await ctx.api.deleteMessage(chatId, active.messageId);
    } catch (error) {
      try {
        await ctx.api.editMessageReplyMarkup(chatId, active.messageId, {
          reply_markup: {inline_keyboard: []},
        });
      } catch (disableError) {
        // The receipt remains transactional even if the old UI cannot be cleaned up.
      }
    }
    clearActiveUiMessage(ctx.session, active.messageId);
  }

  return ctx.reply(t(lang, "booking_confirmed"), {
    reply_markup: {
      inline_keyboard: [[
        {text: t(lang, "btn_main_menu"), callback_data: "menu:main"},
      ]],
    },
  });
}

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

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

    const admin = isLaravelMode() ? null : await db("users").where({is_admin: true}).first();
    if (!isLaravelMode() && !admin) {
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_admin_missing",
        text: t(lang, "booking_admin_missing"),
        navigationMode: "replace",
      });
    }

    const rentals = isLaravelMode()
      ? sessionCart.getCart(ctx)
      : await getDraftRentalsForUser(db, user.id);

    if (rentals.length === 0) {
      return ctx.answerCallbackQuery(t(lang, "booking_no_bookings_to_confirm"));
    }

    if (
      isLaravelMode() &&
      rentals.some(
        (rental) =>
          rental.delivery_required && !String(rental.delivery_address || "").trim()
      )
    ) {
      sessionCart.loadOptionsIntoBooking(ctx, ensureBooking(ctx));
      ctx.session.optionsScope = "process";
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_delivery_address_required",
        text: t(lang, "booking_delivery_address_required"),
        replyMarkup: {
          inline_keyboard: [[{
            text: t(lang, "booking_options_set_address"),
            callback_data: "book:options:address",
          }], [{text: t(lang, "btn_back"), callback_data: "book:draft"}]],
        },
        returnContext: {origin: "booking_confirmation"},
        navigationMode: "replace",
      });
    }

    const accepted = isLaravelMode()
      ? Boolean(ctx.session?.acceptTerms && ctx.session?.acceptedTermsVersion)
      : ctx.session?.acceptTerms || rentals.some((rental) => rental.accept_terms === true);

    if (!accepted) {
      ctx.session.termsOrigin = "booking_confirmation";
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_terms_required",
        text: t(lang, "conditions_accept_required"),
        parseMode: "HTML",
        replyMarkup: {
          inline_keyboard: [
            [
              {
                text: t(lang, "conditions_view_btn"),
                callback_data: "conditions:open",
              },
            ],
            [
              {
                text: t(lang, "conditions_accept_btn"),
                callback_data: "conditions:accept",
              },
            ],
            [{text: t(lang, "btn_back"), callback_data: "book:draft"}],
          ],
        },
        returnContext: {termsOrigin: "booking_confirmation"},
      });
    }

    let confirmedRentals;
    if (isLaravelMode()) {
      const confirmationKey = sessionCart.confirmationKey(ctx);

      if (!sessionCart.hasConfirmationAttempt(ctx, confirmationKey)) {
        const quotes = await Promise.all(rentals.map((rental) => gateway.quote(
          rental.bike_id,
          rental.start_date,
          rental.end_date
        )));
        if (quotes.some((quote) => !quote.available)) {
          const error = new Error("overlap_conflict");
          error.code = "overlap_conflict";
          throw error;
        }

        if (sessionCart.applyAuthoritativeQuotes(ctx, quotes)) {
          return require("./book_draft").showBookingDraft(ctx, {
            lang,
            user,
            notice: t(lang, "booking_quote_changed_reconfirm"),
            backAction: "menu:main",
            navigationMode: "replace",
          });
        }
      }

      sessionCart.markConfirmationAttempt(ctx, confirmationKey);
      confirmedRentals = (await gateway.createBookings(
        ctx,
        sessionCart.toApiItems(ctx),
        confirmationKey
      )).map((entry) => entry.booking);
    } else {
      confirmedRentals = await confirmDraftRentals(db, {userId: user.id});
    }

    if (!confirmedRentals.length) {
      return ctx.answerCallbackQuery(t(lang, "booking_no_bookings_to_confirm"));
    }

    await sendConfirmationReceipt(ctx, lang);

    if (isLaravelMode()) {
      sessionCart.clear(ctx);
      ctx.session.booking = null;
      return;
    }

    for (const rental of confirmedRentals) {
      const bike = await db("bikes").where({id: rental.bike_id}).first();
      const startLabel = rental.start_at
        ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
        : dayjs(rental.start_date).format("DD.MM.YYYY");
      const endLabel = rental.end_at
        ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
        : dayjs(rental.end_date).format("DD.MM.YYYY");
      const priceLabel = rental.total_price
        ? `${rental.total_price} THB`
        : t(lang, "booking_price_tbd");
      const depositLabel = rental.deposit_required
        ? `${rental.deposit_required} THB`
        : "-";
      const docsLabel = rental.docs_missing
        ? t(lang, "admin_docs_missing")
        : t(lang, "admin_docs_ok");

      const text = tHtml(lang, "booking_admin_new", {
        user: user.name || t(lang, "user_no_name"),
        username: user.telegram_name || "-",
        phone: user.phone || "-",
        bike: bike?.name || "-",
        start: startLabel,
        end: endLabel,
        price: priceLabel,
        deposit: depositLabel,
        docs: docsLabel,
        comment: rental.comment || "-",
        helmets: rental.helmets_qty || 0,
        delivery: rental.delivery_required
          ? t(lang, "booking_options_delivery_on")
          : t(lang, "booking_options_delivery_off"),
        address: rental.delivery_address || "-",
      });

      await ctx.api.sendMessage(admin.telegram_id, text, {
        parse_mode: "HTML",
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: t(lang, "admin_btn_approve"),
                callback_data: `admin:rental:approve:${rental.id}`,
              },
              {
                text: t(lang, "admin_btn_cancel"),
                callback_data: `admin:rental:cancel:${rental.id}`,
              },
            ],
          ],
        },
      });
    }
  } catch (err) {
    if (err.code === "BOT_API_UNAVAILABLE") throw err;
    if (err.code === "TERMS_VERSION_OUTDATED") {
      sessionCart.clearConfirmationAttempt(ctx);
      sessionCart.clearTermsAcceptance(ctx);
      ctx.session.termsOrigin = "booking_confirmation";
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_terms_required",
        text: t(lang, "conditions_version_changed"),
        replyMarkup: {
          inline_keyboard: [[{
            text: t(lang, "conditions_accept_btn"),
            callback_data: "conditions:accept_toggle",
          }], [{text: t(lang, "btn_back"), callback_data: "book:draft"}]],
        },
        returnContext: {termsOrigin: "booking_confirmation"},
        navigationMode: "replace",
      });
    }
    if (["overlap_conflict", "VEHICLE_UNAVAILABLE"].includes(err.code)) {
      sessionCart.clearConfirmationAttempt(ctx);
      return botScreenRenderer.renderText(ctx, {
        screen: "booking_conflict",
        text: t(lang, "booking_bike_busy", {name: ""}),
        replyMarkup: {inline_keyboard: [[{
          text: t(lang, "btn_back"),
          callback_data: "book:draft",
        }]]},
        navigationMode: "replace",
      });
    }
    console.error("Ошибка подтверждения бронирования:", err);
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_error",
      text: t(lang, "booking_add_error"),
      replyMarkup: {inline_keyboard: [[{
        text: t(lang, "btn_back"),
        callback_data: "book:draft",
      }]]},
      navigationMode: "replace",
    });
  }
};

module.exports.sendConfirmationReceipt = sendConfirmationReceipt;
