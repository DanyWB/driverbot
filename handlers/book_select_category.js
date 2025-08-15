const {Composer} = require("grammy");
const composer = new Composer();
const db = require("../connect");

composer.callbackQuery(/^book:cat:(\d+)$/, async (ctx) => {
  console.log("✅ Хендлер категории сработал:", ctx.callbackQuery.data);
  const categoryId = Number(ctx.match[1]);
  ctx.session.booking = ctx.session.booking || {};
  ctx.session.booking.categoryId = categoryId;

  const bikes = await db("bikes")
    .select("id", "name")
    .where({category_id: categoryId});

  if (!bikes.length) {
    return ctx.editMessageText("🚫 В этой категории пока нет байков.", {
      reply_markup: {
        inline_keyboard: [[{text: "↩️ Назад", callback_data: "book:start"}]],
      },
    });
  }

  const buttons = bikes.map((bike) => [
    {
      text: bike.name,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);
  buttons.push([{text: "🔙 Назад", callback_data: "book:start"}]);

  await ctx.editMessageText("🛵 Выберите байк:", {
    reply_markup: {inline_keyboard: buttons},
  });
});
module.exports = composer;
