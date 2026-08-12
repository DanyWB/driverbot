const assert = require("node:assert/strict");
const test = require("node:test");
const {
  generateCalendarKeyboard,
  getCalendarBackAction,
  getCalendarDisplayRange,
  isIsoCalendarDay,
} = require("../utils/calendar");

function buttonFor(keyboard, callbackData) {
  return keyboard.inline_keyboard
    .flat()
    .find((button) => button.callback_data === callbackData);
}

test("calendar exposes selectable tail days from the next month", () => {
  const keyboard = generateCalendarKeyboard(2026, 6, [], {lang: "en"});
  const tail = buttonFor(keyboard, "book:select_date:2026-07-03");

  assert.equal(tail.text, "3›");
  assert.deepEqual(getCalendarDisplayRange(2026, 6), {
    startDate: "2026-06-01",
    endDate: "2026-07-05",
  });
});

test("calendar blocks unavailable tail days", () => {
  const keyboard = generateCalendarKeyboard(2026, 6, ["2026-07-03"], {
    lang: "en",
  });

  assert.equal(buttonFor(keyboard, "book:select_date:2026-07-03"), undefined);
  assert.ok(
    keyboard.inline_keyboard.flat().some((button) => button.text === "⛔")
  );
});

test("December tail callbacks point to January of the next year", () => {
  const keyboard = generateCalendarKeyboard(2026, 12, [], {lang: "en"});

  assert.equal(
    buttonFor(keyboard, "book:select_date:2027-01-01").text,
    "1›"
  );
});

test("leap February keeps the real date and correct displayed range", () => {
  const keyboard = generateCalendarKeyboard(2028, 2, [], {lang: "en"});

  assert.ok(buttonFor(keyboard, "book:select_date:2028-02-29"));
  assert.deepEqual(getCalendarDisplayRange(2028, 2), {
    startDate: "2028-01-31",
    endDate: "2028-03-05",
  });
});

test("calendar callback dates require a strict ISO day", () => {
  assert.equal(isIsoCalendarDay("2026-07-03"), true);
  assert.equal(isIsoCalendarDay("2026-02-30"), false);
  assert.equal(isIsoCalendarDay("2026-7-3"), false);
});

test("calendar back preserves the active booking scenario", () => {
  assert.equal(
    getCalendarBackAction({scenario: "bike_first", categoryId: 7, step: "select_start_date"}),
    "book:cat:7"
  );
  assert.equal(
    getCalendarBackAction({scenario: "bike_first", step: "select_end_date"}),
    "book:calendar_back_start"
  );
  assert.equal(
    getCalendarBackAction({scenario: "date_first", step: "select_start_date"}),
    "book:start"
  );
});
