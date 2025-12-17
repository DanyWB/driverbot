const {generateCalendarKeyboard} = require("../utils/calendar");
const dayjs = require("dayjs");
const db = require("../connect");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data; // например: 'calendar:2025-07-06'
  const date = data.split(":")[1];

  if (!ctx.session.booking) {
    return ctx.answerCallbackQuery(
      "Ошибка: нет активного сценария бронирования."
    );
  }

  const {booking} = ctx.session;

  if (!booking.startDate) {
    booking.startDate = date;

    await ctx.editMessageText(
      `📅 Дата начала аренды: ${dayjs(date).format(
        "DD.MM.YYYY"
      )}\n\nТеперь выберите дату окончания аренды.`,
      {
        reply_markup: generateCalendarKeyboard(date, {
          startDate: date,
        }),
      }
    );
    return;
  }

  if (!booking.endDate) {
    const start = dayjs(booking.startDate);
    const end = dayjs(date);

    if (end.isBefore(start)) {
      return ctx.answerCallbackQuery(
        "Дата окончания не может быть раньше начала."
      );
    }

    booking.endDate = date;

    await ctx.editMessageText(
      `📅 Вы выбрали период аренды:\n${start.format(
        "DD.MM.YYYY"
      )} - ${end.format("DD.MM.YYYY")}\n\nТеперь выберите байк.`,
      {
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: "Выбрать байк",
                callback_data: "book:show_available_bikes",
              },
            ],
            [{text: "Назад", callback_data: "book:restart"}],
          ],
        },
      }
    );
  }
};
