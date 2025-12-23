const {Composer} = require("grammy");
const composer = new Composer();
const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");

composer.callbackQuery(/^book:cat:(\d+)$/, async (ctx) => {
  const categoryId = Number(ctx.match[1]);
  const lang = getCtxLang(ctx);
  ctx.session.booking = ctx.session.booking || {};
  ctx.session.booking.categoryId = categoryId;

  const bikes = await db("bikes")
    .select("id", "name")
    .where({category_id: categoryId});

  if (!bikes.length) {
    return ctx.editMessageText(t(lang, "booking_no_bikes_in_category"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "book:start"}]],
      },
    });
  }

  const buttons = bikes.map((bike) => [
    {
      text: bike.name,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);
  buttons.push([{text: t(lang, "btn_back"), callback_data: "book:start"}]);

  await ctx.editMessageText(t(lang, "booking_choose_bike"), {
    reply_markup: {inline_keyboard: buttons},
  });
});
module.exports = composer;
