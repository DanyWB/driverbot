const {generateCalendarKeyboard} = require("../utils/calendar");
const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");

module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data || "";
  const [, scenario] = action.split(":");
  const booking = ensureBooking(ctx);

  booking.scenario = scenario;
  booking.step =
    scenario === "date_first" ? "select_start_date" : "select_bike_category";
  booking.selectedBikeId = null;
  booking.startDate = null;
  booking.endDate = null;
  booking.calendarMonth = dayjs().month() + 1;
  booking.calendarYear = dayjs().year();

  if (scenario === "date_first") {
    const keyboard = generateCalendarKeyboard(
      booking.calendarYear,
      booking.calendarMonth
    );

    await ctx.editMessageText(
      "📅 Выберите дату начала аренды.",
      {reply_markup: keyboard}
    );
  } else if (scenario === "bike_first") {
    await ctx.editMessageText("🏍️ Сначала выберите категорию байков.", {
      reply_markup: {
        inline_keyboard: [
          [{text: "🌿 Light (110-125cc)", callback_data: "book:cat:1"}],
          [{text: "✨ Comfort (150-160cc)", callback_data: "book:cat:2"}],
          [{text: "🏎️ Maxy (300-350cc)", callback_data: "book:cat:3"}],
          [{text: "⬅️ Назад", callback_data: "book:start"}],
        ],
      },
    });
  }
};
