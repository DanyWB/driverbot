module.exports = async (ctx) => {
  ctx.session.step = "awaiting_comment";

  await ctx.editMessageText(
    "✍️ Введите ваши пожелания (например, «двойной шлем», «доставка в отель»):",
    {
      reply_markup: {
        inline_keyboard: [
          [{text: "↩️ Назад", callback_data: "book:add_rental"}],
        ],
      },
    }
  );
};
