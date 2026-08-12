const assert = require("node:assert/strict");
const test = require("node:test");
const {t} = require("../utils/i18n");
const {historyButtonText} = require("../utils/rentalPresentation");
const {
  PAGE_SIZE,
  handleLaravelRentMenuActionWithDeps,
  listScreenPayload,
  normalizeBookingPage,
} = require("../handlers/laravel_rent_menu");
const {
  buildDetailsPayload,
  handleLaravelRentDetailsWithDeps,
} = require("../handlers/laravel_rent_details");

function rental(index, overrides = {}) {
  return {
    booking_public_id: `00000000-0000-4000-8000-${String(index).padStart(12, "0")}`,
    status: "completed",
    start_date: `2026-0${(index % 8) + 1}-01`,
    end_date: `2026-0${(index % 8) + 1}-05`,
    total_price: 2500,
    created_at: "2026-01-02T10:30:00+07:00",
    bike_name: `Honda Click ${index}`,
    vehicle: {
      name: `Honda Click ${index}`,
      type: "scooter",
      category: {code: "comfort-scooters", vehicle_type: "scooter"},
    },
    price: {total_days: 5},
    cancellation: {reason: null, requires_manager: false},
    can_cancel: false,
    ...overrides,
  };
}

test("history uses six items per page and exact gateway total metadata", () => {
  const result = normalizeBookingPage({
    items: Array.from({length: 7}, (_, index) => rental(index + 1)),
    total: 30,
    hasMore: true,
  }, 1);

  assert.equal(PAGE_SIZE, 6);
  assert.equal(result.items.length, 6);
  assert.equal(result.totalPages, 5);
  assert.equal(result.hasNext, true);
});

test("history rows show period and vehicle without UUID or null values", () => {
  const pageResult = {
    items: Array.from({length: 6}, (_, index) => rental(index + 1)),
    total: 13,
    totalPages: 3,
    hasNext: true,
  };
  const payload = listScreenPayload({
    scope: "history",
    page: 1,
    pageResult,
    lang: "en",
  });
  const details = payload.replyMarkup.inline_keyboard
    .flat()
    .filter((button) => button.callback_data.startsWith("rent:details:"));

  assert.equal(details.length, 6);
  assert.match(details[0].text, /^\d{2}\.\d{2}–\d{2}\.\d{2} · Honda Click/);
  assert.equal(details.some((button) => /00000000|null|undefined/i.test(button.text)), false);
  assert.match(payload.text, /2 \/ 3/);
});

test("cross-year history periods remain unambiguous", () => {
  assert.match(historyButtonText(rental(1, {
    start_date: "2026-12-30",
    end_date: "2027-01-02",
  })), /^30\.12\.2026–02\.01\.2027 · Honda Click/);
});

test("history handler requests saved page and keeps it for details back navigation", async () => {
  let request;
  let rendered;
  const ctx = {
    callbackQuery: {data: "rent:history:1"},
    from: {id: 77},
    session: {lang: "en", currentScreen: "rent_history"},
  };
  await handleLaravelRentMenuActionWithDeps(ctx, {
    async listBookingsPage(...args) {
      request = args;
      return {items: [rental(7)], total: 13, hasMore: true};
    },
    renderer: {async renderText(_ctx, payload) { rendered = payload; }},
  });

  assert.deepEqual(request, [77, "history", {limit: 7, offset: 6}]);
  assert.equal(ctx.session.historyPage, 1);
  assert.equal(rendered.screen, "rent_history");
  assert.equal(rendered.navigationMode, "replace");
});

test("details expose localized public fields and safely escaped refusal reason", () => {
  const item = rental(9, {
    status: "cancelled",
    vehicle: {
      name: "Yamaha XMAX",
      type: "scooter",
      category: {code: "maxi-scooters", vehicle_type: "scooter"},
    },
    bike_name: "Yamaha XMAX",
    cancellation: {
      reason: "<b>Нет документов & отказ</b>",
      requires_manager: false,
    },
  });
  const payload = buildDetailsPayload(item, "ru", {origin: "history", page: 2});

  assert.equal(payload.text.includes(item.booking_public_id), false);
  assert.equal(payload.text.includes("null"), false);
  assert.equal(payload.text.includes("cancelled"), false);
  assert.match(payload.text, /&lt;b&gt;.*&amp;.*&lt;\/b&gt;/);
  assert.ok(payload.text.includes(t("ru", "category_maxi_label")));
  assert.ok(payload.text.includes("02.01.2026"));
  assert.equal(
    payload.replyMarkup.inline_keyboard[0][0].callback_data,
    "rent:history:2"
  );
});

test("details opened from page two return to the saved history page", async () => {
  let rendered;
  const item = rental(7);
  const ctx = {
    callbackQuery: {data: `rent:details:${item.booking_public_id}`},
    from: {id: 77},
    session: {lang: "en", currentScreen: "rent_history", historyPage: 1},
  };
  await handleLaravelRentDetailsWithDeps(ctx, {
    async getBooking() { return item; },
    renderer: {async renderText(_ctx, payload) { rendered = payload; }},
  });

  assert.equal(rendered.returnContext.page, 1);
  assert.equal(
    rendered.replyMarkup.inline_keyboard[0][0].callback_data,
    "rent:history:1"
  );
});
