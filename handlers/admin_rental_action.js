const db = require("../connect");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data; // e.g. admin:rental:approve:17
  const [_, __, action, rentalIdRaw] = data.split(":");
  const rentalId = Number(rentalIdRaw);

  const rental = await db("rentals").where({id: rentalId}).first();
  if (!rental) return ctx.reply("Аренда не найдена.");

  const newStatus = action === "approve" ? "approved" : "cancelled";

  await db("rentals").where({id: rentalId}).update({status: newStatus});

  await ctx.editMessageReplyMarkup({inline_keyboard: []});
  await ctx.editMessageText(
    `Заявка #${rentalId} ${
      newStatus === "approved" ? "подтверждена" : "отклонена"
    }.`
  );
};
