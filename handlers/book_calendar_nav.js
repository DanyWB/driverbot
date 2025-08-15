const dayjs = require("dayjs");
const {generateCalendarKeyboard} = require("../utils/calendar");

module.exports = async (ctx) => {
  const action = ctx.callbackQuery.data; // например "book:calendar_prev"

  const direction = action.endsWith("prev") ? -1 : 1;

  if (!ctx.session.booking) {
    return ctx.answerCallbackQuery("Ошибка: нет активного сценария.");
  }

  const booking = ctx.session.booking;

  const currentMonth = booking.calendarMonth ?? dayjs().month();
  const currentYear = booking.calendarYear ?? dayjs().year();

  const newDate = dayjs(`${currentYear}-${currentMonth + 1}-01`).add(
    direction,
    "month"
  );

  booking.calendarMonth = newDate.month();
  booking.calendarYear = newDate.year();

  const keyboard = generateCalendarKeyboard(
    booking.calendarYear,
    booking.calendarMonth
  );

  await ctx.editMessageReplyMarkup({reply_markup: keyboard});
};
