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

function getCalendarDisplayRange(yearInput, monthInput) {
  const {year, month} = resolveYearMonth(yearInput, monthInput);
  const monthStart = dayjs(
    `${year}-${String(month).padStart(2, "0")}-01`
  );
  const cal = new calendar.Calendar(0); // 0 = Monday
  const weeks = cal.monthdayscalendar(year, month);
  const mondayOffset = (monthStart.day() + 6) % 7;
  const start = monthStart.subtract(mondayOffset, "day");
  const end = start.add(weeks.length * 7 - 1, "day");

  return {
    startDate: start.format("YYYY-MM-DD"),
    endDate: end.format("YYYY-MM-DD"),
  };
}

function isIsoCalendarDay(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ""))) return false;
  const parsed = dayjs(value);
  return parsed.isValid() && parsed.format("YYYY-MM-DD") === value;
}

function getCalendarBackAction(booking = {}) {
  if (booking.step === "select_end_date") {
    return "book:calendar_back_start";
  }

  if (
    booking.scenario === "bike_first" &&
    Number.isSafeInteger(Number(booking.categoryId)) &&
    Number(booking.categoryId) > 0
  ) {
    return `book:cat:${Number(booking.categoryId)}`;
  }

  return "book:start";
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
  const minDate = resolvedOptions.minDate
    ? dayjs(resolvedOptions.minDate).startOf("day")
    : null;
  const disablePast = Boolean(resolvedOptions.disablePast);
  const selectedDate = resolvedOptions.selectedDate
    ? dayjs(resolvedOptions.selectedDate).format("YYYY-MM-DD")
    : null;
  const earliestDate = minDate || (disablePast ? dayjs().startOf("day") : null);

  const bookedSet = new Set(bookedDates);
  const cal = new calendar.Calendar(0); // 0 = Monday
  const monthDates = cal.monthdayscalendar(year, month);
  const monthName = dayjs(
    `${year}-${String(month).padStart(2, "0")}-01`
  )
    .locale(locale)
    .format("MMMM YYYY");

  const keyboard = [];
  const nextMonthStart = dayjs(
    `${year}-${String(month).padStart(2, "0")}-01`
  ).add(1, "month");
  let nextMonthDay = 1;
  let hasCurrentMonthDay = false;

  keyboard.push([{text: monthName, callback_data: "noop"}]);

  keyboard.push(weekdays.map((d) => ({text: d, callback_data: "noop"})));

  for (const week of monthDates) {
    const row = week.map((day) => {
      let dateStr;
      let displayDay;
      let isNextMonth = false;

      if (day === 0) {
        if (!hasCurrentMonthDay) return {text: " ", callback_data: "noop"};
        const tailDate = nextMonthStart.date(nextMonthDay);
        dateStr = tailDate.format("YYYY-MM-DD");
        displayDay = nextMonthDay;
        nextMonthDay += 1;
        isNextMonth = true;
      } else {
        hasCurrentMonthDay = true;
        displayDay = day;
        dateStr = `${year}-${String(month).padStart(2, "0")}-${String(
          day
        ).padStart(2, "0")}`;
      }

      const isPast =
        earliestDate && dayjs(dateStr).isBefore(earliestDate, "day");
      const isBlocked = isPast || bookedSet.has(dateStr);
      if (selectedDate && dateStr === selectedDate) {
        return {
          text: `[${displayDay}${isNextMonth ? "›" : ""}]`,
          callback_data: isBlocked ? "noop" : `book:select_date:${dateStr}`,
        };
      }
      if (isBlocked) {
        return {text: labels.blocked || "⛔", callback_data: "noop"};
      }
      return {
        text: `${displayDay}${isNextMonth ? "›" : ""}`,
        callback_data: `book:select_date:${dateStr}`,
      };
    });
    keyboard.push(row);
  }

  keyboard.push([
    {text: labels.prev || "◀️", callback_data: "book:calendar_prev"},
    {
      text: labels.back || "⬅️",
      callback_data: resolvedOptions.backAction || "book:start",
    },
    {text: labels.next || "▶️", callback_data: "book:calendar_next"},
  ]);

  return {inline_keyboard: keyboard};
}

module.exports = {
  generateCalendarKeyboard,
  getCalendarBackAction,
  getCalendarDisplayRange,
  isIsoCalendarDay,
};
