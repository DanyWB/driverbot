const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const dayjs = require("dayjs");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery?.data || "";
  const parts = data.split(":"); // rent:cancel:ID or rent:cancel_confirm:ID
  const action = parts[1];
  const rentalId = Number(parts[2]);
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) return ctx.reply(t(lang, "not_registered"));

  const rental = await db("rentals").where({id: rentalId, user_id: user.id}).first();
  if (!rental) return ctx.reply(t(lang, "rent_current_empty"));

  if (action === "cancel") {
    if (!["process", "pending", "active", "ready"].includes(rental.status)) {
      return ctx.reply(t(lang, "rent_cannot_cancel"));
    }
    return ctx.reply(
      t(lang, "rent_cancel_confirm", {id: rental.booking_public_id || rental.id}),
      {
        reply_markup: {
          inline_keyboard: [
            [{text: "✅", callback_data: `rent:cancel_confirm:${rentalId}`}],
            [{text: t(lang, "rent_action_back"), callback_data: "rent:current"}],
          ],
        },
      }
    );
  }

  if (action === "cancel_confirm") {
    await db("rentals")
      .where({id: rentalId, user_id: user.id})
      .update({status: "cancelled_by_client", updated_at: dayjs().toISOString()});

    const {deleteRemindersForRental} = require("../utils/reminders");
    await deleteRemindersForRental(db, rentalId);

    // notify admin if exists
    const admin = await db("users").where({is_admin: true}).first();
    if (admin) {
      const textAdmin = `🚨 Отмена клиентом\nID: ${rental.booking_public_id || rental.id}\nПользователь: ${user.name || "-"}\nДаты: ${rental.start_date} - ${rental.end_date}`;
      await ctx.api.sendMessage(admin.telegram_id, textAdmin);
    }

    return ctx.editMessageText(t(lang, "rent_cancelled"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "home"}]],
      },
    });
  }
};
