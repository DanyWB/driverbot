const db = require("../connect");
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
};
