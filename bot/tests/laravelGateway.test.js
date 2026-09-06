const assert = require("node:assert/strict");
const test = require("node:test");

test("uses a fresh idempotency key for each customer sync and profile mutation", async () => {
  const calls = [];
  const fakeApi = {
    async post(path, options) {
      calls.push({method: "POST", path, options});
      return {data: {id: 1, telegram: {id: "123"}, passport: {}, profile_complete: {}}};
    },
    async patch(path, options) {
      calls.push({method: "PATCH", path, options});
      return {data: {id: 1, telegram: {id: "123"}, passport: {}, profile_complete: {}}};
    },
    async get(path, options) {
      calls.push({method: "GET", path, options});
      return {data: []};
    },
  };
  const clientModule = require("../services/botApiClient");
  const originalFactory = clientModule.getBotApiClient;
  clientModule.getBotApiClient = () => fakeApi;
  delete require.cache[require.resolve("../services/laravelGateway")];

  try {
    const gateway = require("../services/laravelGateway");
    const telegramUser = {
      id: 123,
      username: "driver",
      first_name: "Test",
      language_code: "uk-UA",
    };

    await gateway.syncTelegramUser(telegramUser);
    await gateway.syncTelegramUser({...telegramUser, language_code: "de"});
    await gateway.updateProfile(123, {phone: "+66000000000"});
    await gateway.updateProfile(123, {phone: "+66000000000"});

    await gateway.bindAdminTelegram({
      from: {
        id: 123,
        username: "driver",
        first_name: "Test",
        last_name: "Admin",
        language_code: "uk-UA",
      },
      chat: {id: 123, type: "private"},
      update: {update_id: 987},
    }, "ABCD-EFGH");

    await gateway.revokeExposedAdminTelegramBindingCode({
      from: {id: 123},
      update: {update_id: 988},
    }, "WXYZ-2345");

    const keys = calls.map((call) => call.options.idempotencyKey);
    assert.equal(new Set(keys).size, keys.length);
    assert.match(keys[0], /^telegram:123:sync:/);
    assert.match(keys[2], /^telegram:123:profile:/);
    assert.equal(keys[4], "telegram:123:987:admin-telegram-binding");
    assert.equal(keys[5], "telegram:123:988:admin-telegram-binding-code-revoke");
    assert.equal(calls[0].options.body.locale, "ua");
    assert.equal(calls[1].options.body.locale, null);
    assert.deepEqual(calls[4], {
      method: "POST",
      path: "/admin-telegram-bindings",
      options: {
        telegramId: "123",
        idempotencyKey: "telegram:123:987:admin-telegram-binding",
        body: {
          code: "ABCD-EFGH",
          chat_id: "123",
          chat_type: "private",
          username: "driver",
          first_name: "Test",
          last_name: "Admin",
          locale: "ua",
        },
      },
    });
    assert.deepEqual(calls[5], {
      method: "POST",
      path: "/admin-telegram-binding-codes/revoke",
      options: {
        telegramId: "123",
        idempotencyKey: "telegram:123:988:admin-telegram-binding-code-revoke",
        body: {code: "WXYZ-2345"},
      },
    });

    await gateway.listBookings(123, "history", {limit: 6, offset: 12});
    assert.deepEqual(calls.at(-1), {
      method: "GET",
      path: "/customers/me/bookings",
      options: {telegramId: 123, query: {scope: "history", limit: 6, offset: 12}},
    });
  } finally {
    clientModule.getBotApiClient = originalFactory;
    delete require.cache[require.resolve("../services/laravelGateway")];
  }
});

test("normalizes flat and nested booking pagination metadata", () => {
  const gateway = require("../services/laravelGateway");
  assert.deepEqual(
    gateway.bookingPagination(
      {meta: {total: 31, limit: 7, offset: 6, has_more: true}},
      {limit: 7, offset: 6, itemsLength: 7}
    ),
    {total: 31, limit: 7, offset: 6, hasMore: true}
  );
  assert.deepEqual(
    gateway.bookingPagination(
      {meta: {pagination: {total_count: 6, per_page: 7, offset: 0}}},
      {limit: 7, offset: 0, itemsLength: 6}
    ),
    {total: 6, limit: 7, offset: 0, hasMore: false}
  );
});
