const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const {
  confirmDraftRentals,
  getDraftRentalsForUser,
} = require("../services/rentalService");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  try {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return ctx.reply(t(lang, "not_registered"));
    }

    const admin = await db("users").where({is_admin: true}).first();
    if (!admin) {
      return ctx.reply(t(lang, "booking_admin_missing"));
    }

    const rentals = await getDraftRentalsForUser(db, user.id);

    if (rentals.length === 0) {
      return ctx.answerCallbackQuery(t(lang, "booking_no_bookings_to_confirm"));
    }

    const accepted =
      ctx.session?.acceptTerms ||
      rentals.some((rental) => rental.accept_terms === true);

    if (!accepted) {
      return ctx.reply(t(lang, "conditions_accept_required"), {
        parse_mode: "HTML",
        reply_markup: {
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
            [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
          ],
        },
      });
    }

    const confirmedRentals = await confirmDraftRentals(db, {userId: user.id});

    if (!confirmedRentals.length) {
      return ctx.answerCallbackQuery(t(lang, "booking_no_bookings_to_confirm"));
    }

    await ctx.editMessageText(t(lang, "booking_confirmed"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_main_menu"), callback_data: "home"}]],
      },
    });

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
    if (err.code === "overlap_conflict") {
      return ctx.reply(t(lang, "booking_bike_busy", {name: ""}));
    }
    console.error("Ошибка подтверждения бронирования:", err);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
