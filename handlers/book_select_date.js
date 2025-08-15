const showAvailableBikes = require("./book_show_available_bikes");
const db = require("../connect");
const {getBusyDatesForBike} = require("../utils/getBusyDatesForBike");
const dayjs = require("dayjs");
const isSameOrBefore = require("dayjs/plugin/isSameOrBefore");
dayjs.extend(isSameOrBefore);

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data;
  const selectedDate = data.split(":")[2];

  if (!ctx.session.booking) {
    ctx.session.booking = {
      scenario: "date_first",
      step: "select_date",
      selectedBikeId: null,
      startDate: null,
      endDate: null,
    };
  }

  const booking = ctx.session.booking;

  // Шаг 1 — выбираем начальную дату
  if (!booking.startDate) {
    booking.startDate = selectedDate;
    booking.step = "select_end_date";
    // Вычисляем занятые даты для календаря, если байк выбран
    let blockedDays = [];
    if (booking.selectedBikeId) {
      blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
    }
    //

    return ctx.editMessageText("📅 Выберите дату окончания аренды:", {
      reply_markup: require("../utils/calendar").generateCalendarKeyboard(
        Number(selectedDate.split("-")[0]),
        Number(selectedDate.split("-")[1]),
        blockedDays
      ),
    });
  }

  // Шаг 2 — выбираем дату окончания
  booking.endDate = selectedDate;
  booking.step = "dates_selected";
  // ⚠️ Проверка на пересечение с существующими арендованными датами
  if (booking.selectedBikeId) {
    const blockedDays = await getBusyDatesForBike(booking.selectedBikeId, db);
    const start = dayjs(booking.startDate);
    const end = dayjs(booking.endDate);

    const selectedDates = [];
    let current = start;
    while (current.isBefore(end) || current.isSame(end, "day")) {
      selectedDates.push(current.format("YYYY-MM-DD"));
      current = current.add(1, "day");
    }

    const hasConflict = selectedDates.some((date) =>
      blockedDays.includes(date)
    );
    if (hasConflict) {
      try {
        await ctx.deleteMessage(); // удаляет старое сообщение с inline-календарём
      } catch (e) {
        console.warn("Не удалось удалить сообщение:", e.message);
      }
      // сбрасываем выбор
      booking.startDate = null;
      booking.endDate = null;
      ctx.session.booking.step = "select_start_date";
      return ctx.reply(
        "❌ В выбранном диапазоне уже есть занятые даты. Попробуйте снова.\n\n📆 Выберите дату начала аренды:",
        {
          reply_markup: require("../utils/calendar").generateCalendarKeyboard(
            dayjs().year(),
            dayjs().month() + 1,
            blockedDays
          ),
        }
      );
    }
  }

  // Если байк уже выбран — показать финальную информацию
  if (booking.startDate && booking.endDate && booking.selectedBikeId) {
    const bikeHandler = require("./book_select_bike");
    ctx.callbackQuery.data = `book:select_bike:${booking.selectedBikeId}`;
    return bikeHandler(ctx);
  }

  // Если байк еще не выбран — показать доступные байки
  return showAvailableBikes(ctx);
};
