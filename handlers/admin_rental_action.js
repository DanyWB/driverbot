const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data; // e.g. admin:rental:approve:17
  const [_, __, action, rentalIdRaw] = data.split(":");
  const rentalId = Number(rentalIdRaw);
  const lang = getCtxLang(ctx);

  const rental = await db("rentals").where({id: rentalId}).first();
  if (!rental) return ctx.reply(t(lang, "admin_rental_not_found"));

  const newStatus = action === "approve" ? "approved" : "cancelled";

  await db("rentals").where({id: rentalId}).update({status: newStatus});

  await ctx.editMessageReplyMarkup({inline_keyboard: []});
  await ctx.editMessageText(
    t(lang, "admin_request_status", {
      id: rentalId,
      status:
        newStatus === "approved"
          ? t(lang, "admin_status_approved")
          : t(lang, "admin_status_cancelled"),
    })
  );

  if (newStatus === "approved" && rental.user_id) {
    const user = await db("users").where({id: rental.user_id}).first();
    const bike = rental.bike_id
      ? await db("bikes").where({id: rental.bike_id}).first()
      : null;
    if (user && user.telegram_id) {
      const userLang = user.lang || "ru";
      const start = rental.start_at || rental.start_date;
      const end = rental.end_at || rental.end_date;
      const startLabel = start ? dayjs(start).format("DD.MM.YYYY HH:mm") : "-";
      const endLabel = end ? dayjs(end).format("DD.MM.YYYY HH:mm") : "-";
      const statusLabel = t(userLang, "rent_status_approved") || "approved";
      const text = t(userLang, "user_rental_approved", {
        id: rental.booking_public_id || rental.id,
        start: startLabel,
        end: endLabel,
        model: bike?.name || "",
        status: statusLabel,
      });

      await ctx.api.sendMessage(user.telegram_id, text, {
        parse_mode: "HTML",
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: t(userLang, "rent_view_details_btn"),
                callback_data: `rent:details:${rental.id}`,
              },
            ],
            [{text: t(userLang, "btn_home"), callback_data: "home"}],
          ],
        },
      });
    }
  }
};
