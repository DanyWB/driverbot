const dayjs = require("dayjs");
const calendar = require("node-calendar");

function generateCalendarKeyboard(year, month, bookedDates = []) {
  const bookedSet = new Set(bookedDates);
  const cal = new calendar.Calendar(0); // 0 = Monday
  const monthDates = cal.monthdayscalendar(year, month);
  const monthName = dayjs(
    `${year}-${String(month).padStart(2, "0")}-01`
  ).format("MMMM YYYY");

  const keyboard = [];

  keyboard.push([{text: monthName, callback_data: "noop"}]);

  const weekdays = ["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"];
  keyboard.push(weekdays.map((d) => ({text: d, callback_data: "noop"})));

  for (const week of monthDates) {
    const row = week.map((day) => {
      if (day === 0) return {text: " ", callback_data: "noop"};
      const dateStr = `${year}-${String(month).padStart(2, "0")}-${String(
        day
      ).padStart(2, "0")}`;
      if (bookedSet.has(dateStr)) {
        return {text: "⛔", callback_data: "noop"};
      }
      return {text: String(day), callback_data: `book:select_date:${dateStr}`};
    });
    keyboard.push(row);
  }

  keyboard.push([
    {text: "◀️", callback_data: "book:calendar_prev"},
    {text: "⬅️ Назад", callback_data: "book:start"},
    {text: "▶️", callback_data: "book:calendar_next"},
  ]);

  return {inline_keyboard: keyboard};
}

module.exports = {generateCalendarKeyboard};
