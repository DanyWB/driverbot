module.exports = async (ctx) => {
  ctx.session.booking = {
    scenario: null,
    step: null,
    selectedBikeId: null,
    startDate: null,
    endDate: null,
  };

  const text = `🏝 <b>Аренда байка</b>

Выберите удобный для вас способ бронирования:
— сначала выбрать <b>даты</b>, а затем доступные байки;
— или сначала выбрать <b>байк</b>, а затем свободные даты.

👇 Выберите вариант ниже:`;

  await ctx.reply(text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [{text: "📅 Сначала выбрать дату", callback_data: "book:date_first"}],
        [{text: "🛵 Сначала выбрать байк", callback_data: "book:bike_first"}],
      ],
    },
  });
};
