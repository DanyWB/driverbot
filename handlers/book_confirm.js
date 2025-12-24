const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {scheduleRemindersForRental} = require("../utils/reminders");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  try {
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      return ctx.reply(t(lang, "not_registered"));
    }

  const rentals = await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process");

  if (rentals.length === 0) {
    return ctx.answerCallbackQuery(t(lang, "booking_no_bookings_to_confirm"));
  }

   // Require acceptance of rental terms
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
          [{text: t(lang, "btn_back"), callback_data: "home"}],
        ],
      },
    });
  }

  for (const rental of rentals) {
    const {applyOverlapCondition} = require("../utils/overlap");
    const overlapping = await db("rentals")
      .where("bike_id", rental.bike_id)
      .whereNotIn("status", ["cancelled", "cancelled_by_client"])
      .andWhere(function () {
        applyOverlapCondition(
          this,
          rental.start_at,
          rental.end_at,
          rental.start_date,
          rental.end_date
        );
      })
      .andWhere(function () {
        this.whereNot(function () {
          this.where("status", "process").andWhere("user_id", user.id);
        });
      });

    const bike = await db("bikes").where("id", rental.bike_id).first();
    if (overlapping.length > 0) {
      return ctx.reply(
        t(lang, "booking_bike_busy", {name: bike.name})
      );
    }
  }

  const rentalsToConfirm = await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process");

  // transactional check to avoid race
  await db.transaction(async (trx) => {
    for (const rental of rentalsToConfirm) {
      const {hasOverlap, applyOverlapCondition} = require("../utils/overlap");
      const conflict = await trx("rentals")
        .where("bike_id", rental.bike_id)
        .whereNotIn("status", ["cancelled", "cancelled_by_client"])
        .andWhere(function () {
          applyOverlapCondition(
            this,
            rental.start_at,
            rental.end_at,
            rental.start_date,
            rental.end_date
          );
        })
        .andWhereNot({id: rental.id})
        .first();

      if (conflict) {
        throw new Error("overlap_conflict");
      }
    }

    await trx("rentals")
      .where("user_id", user.id)
      .andWhere("status", "process")
      .update({
        status: "active",
        accept_terms: true,
        confirmed_at: dayjs().toISOString(),
      });
  }).catch((err) => {
    if (err.message === "overlap_conflict") {
      throw new Error("overlap_conflict");
    }
    throw err;
  });

  await ctx.editMessageText(t(lang, "booking_confirmed"), {
    reply_markup: {
      inline_keyboard: [[{text: t(lang, "btn_home"), callback_data: "home"}]],
    },
  });

    for (const rental of rentalsToConfirm) {
    const bike = await db("bikes").where({id: rental.bike_id}).first();
    const admin = await db("users").where({is_admin: true}).first();
    if (!admin) {
      await ctx.reply(
        t(lang, "booking_admin_missing")
      );
      return;
    }
    const text = t(lang, "booking_admin_new", {
      user: user.name || t(lang, "user_no_name"),
      username: user.telegram_name || "-",
      bike: bike.name,
      start: dayjs(rental.start_date).format("DD.MM"),
      end: dayjs(rental.end_date).format("DD.MM"),
      price: rental.total_price || t(lang, "booking_price_tbd"),
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

      // schedule reminders
      await scheduleRemindersForRental(db, rental.id);
    }
  } catch (err) {
    if (err.message === "overlap_conflict") {
      return ctx.reply(t(lang, "booking_bike_busy", {name: ""}));
    }
    console.error("Ошибка подтверждения бронирования:", err);
    return ctx.reply(t(lang, "booking_add_error"));
  }
};
