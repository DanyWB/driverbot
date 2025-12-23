const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

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

  for (const rental of rentals) {
    const overlapping = await db("rentals")
      .where("bike_id", rental.bike_id)
      .andWhere("status", "!=", "cancelled")
      .andWhere(function () {
        this.whereBetween("start_date", [rental.start_date, rental.end_date])
          .orWhereBetween("end_date", [rental.start_date, rental.end_date])
          .orWhere(function () {
            this.where("start_date", "<=", rental.start_date).andWhere(
              "end_date",
              ">=",
              rental.end_date
            );
          });
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

  await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process")
    .update({
      status: "active",
      confirmed_at: dayjs().toISOString(),
    });

  await ctx.editMessageText(t(lang, "booking_confirmed"), {
    reply_markup: {
      inline_keyboard: [[{text: t(lang, "btn_home"), callback_data: "home"}]],
    },
  });

  for (const rental of rentals) {
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
      price: rental.total_price,
      comment: rental.comment || "-",
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
};
