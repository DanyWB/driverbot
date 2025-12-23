const dayjs = require("dayjs");
require("dayjs/locale/ru");
require("dayjs/locale/uk");
const calendar = require("node-calendar");
const {
  getWeekdays,
  getDayjsLocale,
  getCalendarLabels,
  normalizeLang,
} = require("./i18n");

function resolveYearMonth(yearInput, monthInput) {
  if (typeof yearInput === "string") {
    const [y, m] = yearInput.split("-");
    return {year: Number(y), month: Number(m)};
  }
  return {year: Number(yearInput), month: Number(monthInput)};
}

function generateCalendarKeyboard(
  yearInput,
  monthInput,
  bookedDates = [],
  options = {}
) {
  let resolvedOptions = options;
  if (
    typeof monthInput === "object" &&
    monthInput !== null &&
    !Array.isArray(monthInput)
  ) {
    resolvedOptions = monthInput;
  }

  const {year, month} = resolveYearMonth(yearInput, monthInput);
  const lang = normalizeLang(resolvedOptions.lang);
  const labels = resolvedOptions.labels || getCalendarLabels(lang);
  const weekdays = resolvedOptions.weekdays || getWeekdays(lang);
  const locale = getDayjsLocale(lang);

  const bookedSet = new Set(bookedDates);
  const cal = new calendar.Calendar(0); // 0 = Monday
  const monthDates = cal.monthdayscalendar(year, month);
  const monthName = dayjs(
    `${year}-${String(month).padStart(2, "0")}-01`
  )
    .locale(locale)
    .format("MMMM YYYY");

  const keyboard = [];

  keyboard.push([{text: monthName, callback_data: "noop"}]);

  keyboard.push(weekdays.map((d) => ({text: d, callback_data: "noop"})));

  for (const week of monthDates) {
    const row = week.map((day) => {
      if (day === 0) return {text: " ", callback_data: "noop"};
      const dateStr = `${year}-${String(month).padStart(2, "0")}-${String(
        day
      ).padStart(2, "0")}`;
      if (bookedSet.has(dateStr)) {
        return {text: labels.blocked || "⛔", callback_data: "noop"};
      }
      return {text: String(day), callback_data: `book:select_date:${dateStr}`};
    });
    keyboard.push(row);
  }

  keyboard.push([
    {text: labels.prev || "◀️", callback_data: "book:calendar_prev"},
    {text: labels.back || "⬅️", callback_data: "book:start"},
    {text: labels.next || "▶️", callback_data: "book:calendar_next"},
  ]);

  return {inline_keyboard: keyboard};
}

module.exports = {generateCalendarKeyboard};
