const {generateCalendarKeyboard} = require("../utils/calendar");
const dayjs = require("dayjs");

module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data || "";
  const [, scenario] = action.split(":");
  ctx.session.booking = {
    scenario,
    step:
      scenario === "date_first" ? "select_start_date" : "select_bike_category",
    selectedBikeId: null,
    startDate: null,
    endDate: null,
    calendarMonth: dayjs().month(),
    calendarYear: dayjs().year(),
  };

  if (scenario === "date_first") {
    const keyboard = generateCalendarKeyboard(
      ctx.session.booking.calendarYear,
      ctx.session.booking.calendarMonth
    );

    await ctx.editMessageText(
      "📆 Отлично! Пожалуйста, выберите дату начала аренды.",
      {reply_markup: keyboard}
    );
  } else if (scenario === "bike_first") {
    await ctx.editMessageText(
      "🛵 Отлично! Сначала выберите категорию байков.",
      {
        reply_markup: {
          inline_keyboard: [
            [{text: "🟢 Light (110–125cc)", callback_data: "book:cat:1"}],
            [{text: "🟡 Comfort (150–160cc)", callback_data: "book:cat:2"}],
            [{text: "🔴 Maxy (300–350cc)", callback_data: "book:cat:3"}],
            [{text: "↩️ Назад", callback_data: "book:start"}],
          ],
        },
      }
    );
  }
};
