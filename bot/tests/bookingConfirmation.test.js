const {after, test} = require("node:test");
const assert = require("node:assert/strict");

const previousMode = process.env.BOT_DATA_MODE;
process.env.BOT_DATA_MODE = "laravel";

const gateway = require("../services/laravelGateway");
const sessionCart = require("../services/sessionCartService");
const bookDraft = require("../handlers/book_draft");
const confirmBooking = require("../handlers/book_confirm");

after(() => {
  if (previousMode === undefined) delete process.env.BOT_DATA_MODE;
  else process.env.BOT_DATA_MODE = previousMode;
});

function context(lang = "en") {
  const replies = [];
  const ctx = {
    from: {id: 100500},
    chat: {id: 100500},
    callbackQuery: {data: "book:confirm_rental", message: {chat: {id: 100500}}},
    session: {
      lang,
      acceptTerms: true,
      acceptedTermsVersion: "2026-09-02",
    },
    api: {},
    answerCallbackQuery: async () => {},
    reply: async (text, options) => {
      replies.push({text, options});
      return {message_id: 99};
    },
  };
  ctx.replies = replies;
  return ctx;
}

function booking(vehicleId) {
  return {
    selectedBikeId: vehicleId,
    startDate: "2026-09-10",
    endDate: "2026-09-12",
    startTime: "10:00",
    endTime: "10:00",
    helmets: 1,
    deliveryRequired: false,
    deliveryAddress: null,
    notes: null,
  };
}

function addRental(ctx, vehicleId, total, currency = "THB") {
  sessionCart.add(
    ctx,
    booking(vehicleId),
    {id: vehicleId, name: `Vehicle ${vehicleId}`},
    {vehicle_id: vehicleId, final_total: total, currency}
  );
}

async function withGatewayMocks(mocks, callback) {
  const originals = {};
  for (const [name, implementation] of Object.entries(mocks)) {
    originals[name] = gateway[name];
    gateway[name] = implementation;
  }
  try {
    return await callback();
  } finally {
    Object.assign(gateway, originals);
  }
}

async function withDraftMock(callback) {
  const original = bookDraft.showBookingDraft;
  const renders = [];
  bookDraft.showBookingDraft = async (_ctx, options) => {
    renders.push(options);
    return {rendered: true, options};
  };
  try {
    return await callback(renders);
  } finally {
    bookDraft.showBookingDraft = original;
  }
}

function created(items) {
  return items.map((item, index) => ({
    client_reference: item.client_reference,
    booking: {public_id: `booking-${index + 1}`},
  }));
}

test("re-quotes an unchanged cart immediately before creating it", async () => {
  const ctx = context();
  addRental(ctx, 7, 700);
  const quoteCalls = [];
  const createCalls = [];

  await withGatewayMocks({
    getProfile: async () => ({id: 1}),
    quote: async (vehicleId, startsOn, endsOn) => {
      quoteCalls.push({vehicleId, startsOn, endsOn});
      return {vehicle_id: vehicleId, final_total: 700, currency: "THB", available: true};
    },
    createBookings: async (_ctx, items, key) => {
      createCalls.push({items, key});
      return created(items);
    },
  }, async () => confirmBooking(ctx));

  assert.deepEqual(quoteCalls, [{
    vehicleId: 7,
    startsOn: "2026-09-10",
    endsOn: "2026-09-12",
  }]);
  assert.equal(createCalls.length, 1);
  assert.match(createCalls[0].key, /^telegram:100500:cart:/);
  assert.deepEqual(sessionCart.getCart(ctx), []);
  assert.equal(ctx.replies.length, 1);
});

test("updates a changed quote and requires a second explicit confirmation", async () => {
  const ctx = context("en");
  addRental(ctx, 7, 700);
  const firstKey = sessionCart.confirmationKey(ctx);
  let createCount = 0;

  await withGatewayMocks({
    getProfile: async () => ({id: 1}),
    quote: async (vehicleId) => ({
      vehicle_id: vehicleId,
      final_total: 900,
      currency: "THB",
      available: true,
    }),
    createBookings: async (_ctx, items) => {
      createCount += 1;
      return created(items);
    },
  }, async () => withDraftMock(async (renders) => {
    await confirmBooking(ctx);

    assert.equal(createCount, 0);
    assert.equal(sessionCart.getCart(ctx)[0].total_price, 900);
    assert.notEqual(sessionCart.confirmationKey(ctx), firstKey);
    assert.match(renders[0].notice, /price has changed/i);

    await confirmBooking(ctx);
    assert.equal(createCount, 1);
    assert.deepEqual(sessionCart.getCart(ctx), []);
  }));
});

test("one changed quote blocks creation of the whole mixed batch", async () => {
  const ctx = context("ru");
  addRental(ctx, 7, 700);
  addRental(ctx, 8, 900);
  let createCount = 0;

  await withGatewayMocks({
    getProfile: async () => ({id: 1}),
    quote: async (vehicleId) => ({
      vehicle_id: vehicleId,
      final_total: vehicleId === 8 ? 1100 : 700,
      currency: "THB",
      available: true,
    }),
    createBookings: async () => {
      createCount += 1;
      return [];
    },
  }, async () => withDraftMock(async (renders) => {
    await confirmBooking(ctx);

    assert.equal(createCount, 0);
    assert.deepEqual(
      sessionCart.getCart(ctx).map((item) => item.total_price),
      [700, 1100]
    );
    assert.match(renders[0].notice, /Цена изменилась/);
  }));
});

test("an uncertain create retry preserves its key and does not re-quote", async () => {
  const ctx = context();
  addRental(ctx, 7, 700);
  let quoteCount = 0;
  const createKeys = [];

  await withGatewayMocks({
    getProfile: async () => ({id: 1}),
    quote: async (vehicleId) => {
      quoteCount += 1;
      return {vehicle_id: vehicleId, final_total: 700, currency: "THB", available: true};
    },
    createBookings: async (_ctx, items, key) => {
      createKeys.push(key);
      if (createKeys.length === 1) {
        const error = new Error("timeout");
        error.code = "BOT_API_UNAVAILABLE";
        throw error;
      }
      return created(items);
    },
  }, async () => {
    await assert.rejects(confirmBooking(ctx), /timeout/);
    await confirmBooking(ctx);
  });

  assert.equal(quoteCount, 1);
  assert.equal(createKeys.length, 2);
  assert.equal(createKeys[0], createKeys[1]);
});
