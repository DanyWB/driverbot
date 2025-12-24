const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const bikeId = Number(data.split(":")[2]);
  const telegramId = ctx.from.id;
  const lang = getCtxLang(ctx);

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply(t(lang, "not_registered"));
  }

  const deleted = await db("rentals")
    .where({id: bikeId, user_id: user.id, status: "process"})
    .del();

  if (!deleted) {
    return ctx.answerCallbackQuery(t(lang, "booking_delete_failed"));
  }
  ctx.session.booking = null;
  const rentals = await db("rentals")
    .join("bikes", "rentals.bike_id", "bikes.id")
    .where("user_id", user.id)
    .andWhere("status", "process")
    .select("rentals.id", "bikes.name", "rentals.start_date", "rentals.end_date");

  if (!rentals.length) {
    return ctx.editMessageText(t(lang, "booking_no_bikes_in_process"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
          [{text: t(lang, "btn_back"), callback_data: "home"}],
        ],
      },
    });
  }

  let text = t(lang, "booking_bike_removed") + "\n\n";
  rentals.forEach((r) => {
    text += `• ${r.name}: ${r.start_date || ""} - ${r.end_date || ""}\n`;
  });

  return ctx.editMessageText(text, {
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "booking_confirm_btn"), callback_data: "book:confirm_rental"}],
        [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
        [{text: t(lang, "btn_back"), callback_data: "home"}],
      ],
    },
  });
};
