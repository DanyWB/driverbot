const test = require("node:test");
const assert = require("node:assert/strict");
const {BotApiClient, BotApiError} = require("../services/botApiClient");

test("retries safe requests and preserves service and identity headers", async () => {
  const calls = [];
  const client = new BotApiClient({
    baseUrl: "https://backend.test/api/v1/bot",
    token: "secret-token",
    timeoutMs: 100,
    maxAttempts: 3,
    fetchImpl: async (url, options) => {
      calls.push({url: String(url), options});
      if (calls.length === 1) {
        return new Response(JSON.stringify({error: {code: "TEMPORARY"}}), {
          status: 503,
          headers: {"content-type": "application/json"},
        });
      }
      return new Response(JSON.stringify({data: [{id: 1}]}), {status: 200});
    },
  });

  const result = await client.get("/vehicles", {
    telegramId: 12345,
    query: {type: "scooter"},
  });

  assert.deepEqual(result.data, [{id: 1}]);
  assert.equal(calls.length, 2);
  assert.match(calls[0].url, /vehicles\?type=scooter$/);
  assert.equal(calls[0].options.headers.Authorization, "Bearer secret-token");
  assert.equal(calls[0].options.headers["X-Telegram-User-ID"], "12345");
  assert.equal(
    calls[0].options.headers["X-Request-ID"],
    calls[1].options.headers["X-Request-ID"]
  );
});

test("retries a safe request when a temporary gateway response is not JSON", async () => {
  let calls = 0;
  const client = new BotApiClient({
    baseUrl: "https://backend.test/api/v1/bot",
    token: "secret-token",
    timeoutMs: 100,
    maxAttempts: 2,
    fetchImpl: async () => {
      calls += 1;
      if (calls === 1) {
        return new Response("<html>Bad gateway</html>", {status: 502});
      }
      return new Response(JSON.stringify({data: [{id: 1}]}), {status: 200});
    },
  });

  const result = await client.get("/vehicles");

  assert.deepEqual(result.data, [{id: 1}]);
  assert.equal(calls, 2);
});

test("does not retry an unsafe mutation without an idempotency key", async () => {
  let calls = 0;
  const client = new BotApiClient({
    baseUrl: "https://backend.test/api/v1/bot",
    token: "token",
    maxAttempts: 3,
    fetchImpl: async () => {
      calls += 1;
      return new Response(JSON.stringify({error: {code: "TEMPORARY", message: "Retry later"}}), {
        status: 503,
      });
    },
  });

  await assert.rejects(
    client.post("/bookings", {body: {items: []}}),
    (error) => error instanceof BotApiError && error.code === "TEMPORARY"
  );
  assert.equal(calls, 1);
});

test("retries an idempotent mutation with the same key and exposes stable error details", async () => {
  const keys = [];
  let calls = 0;
  const client = new BotApiClient({
    baseUrl: "https://backend.test/api/v1/bot",
    token: "token",
    maxAttempts: 2,
    fetchImpl: async (url, options) => {
      calls += 1;
      keys.push(options.headers["Idempotency-Key"]);
      if (calls === 1) return new Response("", {status: 502});
      return new Response(JSON.stringify({
        error: {
          code: "VEHICLE_UNAVAILABLE",
          message: "Vehicle is busy",
          fields: {},
          details: {vehicle_id: 7},
          request_id: "request-7",
        },
      }), {status: 409});
    },
  });

  await assert.rejects(
    client.post("/bookings", {
      body: {items: [{vehicle_id: 7}]},
      idempotencyKey: "cart-key",
    }),
    (error) => {
      assert.equal(error.code, "VEHICLE_UNAVAILABLE");
      assert.equal(error.status, 409);
      assert.equal(error.details.vehicle_id, 7);
      assert.equal(error.requestId, "request-7");
      return true;
    }
  );
  assert.deepEqual(keys, ["cart-key", "cart-key"]);
});
