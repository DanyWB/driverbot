const assert = require("node:assert/strict");
const test = require("node:test");
const {showBikeSummary} = require("../handlers/book_select_bike");
const {
  handleLaravelRentCancelWithDeps,
} = require("../handlers/laravel_rent_cancel");

function booking(overrides = {}) {
  return {
    scenario: "date_first",
    categoryId: 4,
    selectedBikeId: 9,
    startDate: "2026-08-20",
    endDate: "2026-08-22",
    startTime: null,
    endTime: null,
    priceUnknown: false,
    totalPrice: 3000,
    pricePerDay: 1000,
    ...overrides,
  };
}

function vehicle(overrides = {}) {
  return {
    id: 9,
    name: "Honda Click 160",
    description: "Comfort scooter",
    image_url: "https://example.test/click.jpg",
    type: "scooter",
    ...overrides,
  };
}

test("vehicle summary replaces the active UI with one photo and preserves list back", async () => {
  let rendered;
  const renderer = {
    async renderPhoto(_ctx, payload) {
      rendered = payload;
      return {mode: "replaced"};
    },
  };
  const ctx = {session: {lang: "en"}};

  await showBikeSummary(ctx, vehicle(), booking(), {renderer});

  assert.equal(rendered.screen, "booking_bike_summary");
  assert.equal(rendered.photo, "https://example.test/click.jpg");
  assert.equal(
    rendered.replyMarkup.inline_keyboard.at(-1)[0].callback_data,
    "book:back_to_bikes"
  );
  assert.deepEqual(rendered.returnContext, {
    scenario: "date_first",
    categoryId: 4,
    selectedBikeId: 9,
  });
});

test("bike-first summary returns to the end-date calendar without clearing the bike", async () => {
  let rendered;
  const renderer = {
    async renderText(_ctx, payload) {
      rendered = payload;
      return {mode: "edited"};
    },
  };
  const ctx = {session: {lang: "en"}};

  await showBikeSummary(
    ctx,
    vehicle({image_url: null}),
    booking({scenario: "bike_first"}),
    {renderer}
  );

  assert.equal(
    rendered.replyMarkup.inline_keyboard.at(-1)[0].callback_data,
    "book:calendar_back_end"
  );
  assert.equal(rendered.returnContext.selectedBikeId, 9);
});

test("cancel confirmation uses a text action and returns to originating details", async () => {
  let rendered;
  const publicId = "booking-public-id";
  const ctx = {
    callbackQuery: {data: `rent:cancel:${publicId}`},
    from: {id: 77},
    session: {lang: "en", currentScreen: "rent_details", currentRentalsPage: 2},
  };

  await handleLaravelRentCancelWithDeps(ctx, {
    async getBooking() {
      return {
        booking_public_id: publicId,
        can_cancel: true,
        cancellation: {requires_manager: false},
      };
    },
    renderer: {
      async renderText(_ctx, payload) {
        rendered = payload;
      },
    },
  });

  const buttons = rendered.replyMarkup.inline_keyboard.flat();
  assert.equal(rendered.screen, "rent_cancel_confirm");
  assert.equal(buttons[0].text, "Yes, cancel booking");
  assert.equal(buttons[1].callback_data, `rent:details:${publicId}`);
  assert.equal(ctx.session.rentCancelBackAction, `rent:details:${publicId}`);
});

test("confirmed cancellation keeps the saved list page as a recovery route", async () => {
  let rendered;
  let cancellationId;
  const publicId = "booking-public-id";
  const ctx = {
    callbackQuery: {data: `rent:cancel_confirm:${publicId}`},
    from: {id: 77},
    session: {
      lang: "en",
      currentScreen: "rent_cancel_confirm",
      currentRentalsPage: 2,
      rentCancelBackAction: "rent:current:2",
    },
  };

  await handleLaravelRentCancelWithDeps(ctx, {
    async getBooking() {
      return {booking_public_id: publicId, can_cancel: true, cancellation: {}};
    },
    async cancelBooking(_ctx, id) {
      cancellationId = id;
    },
    renderer: {
      async renderText(_ctx, payload) {
        rendered = payload;
      },
    },
  });

  assert.equal(cancellationId, publicId);
  assert.equal(rendered.screen, "rent_cancelled");
  assert.equal(ctx.session.rentCancelBackAction, undefined);
});
