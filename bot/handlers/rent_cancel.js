const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const dayjs = require("dayjs");
const {USER_CANCELLABLE_RENTAL_STATUSES} = require("../utils/rentalStatus");
const {cancelRentalByUser} = require("../services/rentalService");
const {isLaravelMode} = require("../config/runtime");

module.exports = async (ctx) => {
  if (isLaravelMode()) return require("./laravel_rent_cancel")(ctx);
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
    if (!USER_CANCELLABLE_RENTAL_STATUSES.includes(rental.status)) {
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
    const result = await cancelRentalByUser(db, {rentalId, userId: user.id});
    if (result.status !== "cancelled") {
      return ctx.reply(t(lang, "rent_cannot_cancel"));
    }

    const cancelledRental = result.rental;

    // notify admin if exists
    const admin = await db("users").where({is_admin: true}).first();
    if (admin) {
      const startLabel = cancelledRental.start_at
        ? dayjs(cancelledRental.start_at).format("DD.MM.YYYY HH:mm")
        : dayjs(cancelledRental.start_date).format("DD.MM.YYYY");
      const endLabel = cancelledRental.end_at
        ? dayjs(cancelledRental.end_at).format("DD.MM.YYYY HH:mm")
        : dayjs(cancelledRental.end_date).format("DD.MM.YYYY");
      const textAdmin = tHtml(lang, "admin_cancel_by_client", {
        id: cancelledRental.booking_public_id || cancelledRental.id,
        user: user.name || "-",
        username: user.telegram_name || "-",
        phone: user.phone || "-",
        start: startLabel,
        end: endLabel,
      });
      await ctx.api.sendMessage(admin.telegram_id, textAdmin, {
        parse_mode: "HTML",
      });
    }

    return ctx.editMessageText(t(lang, "rent_cancelled"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
        ],
      },
    });
  }
};
