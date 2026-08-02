const assert = require("node:assert/strict");
const {performance} = require("node:perf_hooks");
const {randomUUID} = require("node:crypto");
require("dotenv").config();
const {BotApiClient, BotApiError} = require("../services/botApiClient");

function futureDate(days) {
  const date = new Date(Date.now() + days * 24 * 60 * 60 * 1000);
  return date.toISOString().slice(0, 10);
}

function percentile(values, ratio) {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.min(sorted.length - 1, Math.ceil(sorted.length * ratio) - 1)];
}

async function availableWindow(client) {
  for (let offset = 120; offset <= 360; offset += 8) {
    const startsOn = futureDate(offset);
    const endsOn = futureDate(offset + 5);
    const response = await client.get("/vehicles/available", {
      query: {start_date: startsOn, end_date: endsOn},
    });
    if (response.data.length > 0) return {vehicle: response.data[0], startsOn, endsOn};
  }
  throw new Error("No six-day availability window was found in the next year.");
}

async function prepareCustomer(client, telegramId, runId) {
  await client.post("/customers/sync", {
    body: {
      telegram_id: telegramId,
      username: `load_${telegramId}`,
      first_name: "Load",
      locale: "en",
    },
    idempotencyKey: `load:${runId}:${telegramId}:sync`,
  });
  await client.patch("/customers/me", {
    telegramId,
    body: {name: `Load Test ${telegramId}`, locale: "en", phone: "+66900000000"},
    idempotencyKey: `load:${runId}:${telegramId}:profile`,
  });
}

async function main() {
  const requestedClients = Number.parseInt(process.env.LOAD_CLIENTS || "10", 10);
  const requestedP95 = Number.parseInt(process.env.LOAD_P95_MAX_MS || "3000", 10);
  const clients = Number.isInteger(requestedClients) ? Math.min(30, Math.max(2, requestedClients)) : 10;
  const maxP95 = Number.isInteger(requestedP95) ? Math.max(100, requestedP95) : 3000;
  const api = new BotApiClient({maxAttempts: 1, timeoutMs: 10000});
  const runId = `${Date.now()}-${randomUUID().slice(0, 8)}`;
  const configuration = await api.get("/configuration");
  const {vehicle, startsOn, endsOn} = await availableWindow(api);
  const baseTelegramId = BigInt(`8${Date.now().toString().slice(-11)}`);
  const telegramIds = Array.from({length: clients}, (_, index) => String(baseTelegramId + BigInt(index)));

  await Promise.all(telegramIds.map((telegramId) => prepareCustomer(api, telegramId, runId)));

  const bookingBody = (index) => ({
    terms_accepted: true,
    terms_version: configuration.data.terms_version,
    items: [{
      client_reference: `load-${runId}-${index}`,
      vehicle_id: vehicle.id,
      starts_on: startsOn,
      ends_on: endsOn,
      pickup_time: "10:00",
      return_time: "10:00",
      helmets_quantity: 1,
      delivery_required: false,
      client_comment: `Stage 10 concurrency test ${runId}`,
    }],
  });

  const attempts = await Promise.all(telegramIds.map(async (telegramId, index) => {
    const startedAt = performance.now();
    try {
      const response = await api.post("/bookings", {
        telegramId,
        body: bookingBody(index),
        idempotencyKey: `load:${runId}:${telegramId}:booking`,
      });
      return {ok: true, telegramId, response, duration: performance.now() - startedAt};
    } catch (error) {
      return {ok: false, telegramId, error, duration: performance.now() - startedAt};
    }
  }));

  const successful = attempts.filter((attempt) => attempt.ok);
  const conflicts = attempts.filter(
    (attempt) => !attempt.ok && attempt.error instanceof BotApiError && attempt.error.status === 409
  );
  const unexpected = attempts.filter((attempt) => !attempt.ok && !conflicts.includes(attempt));
  const durations = attempts.map((attempt) => attempt.duration);

  assert.equal(successful.length, 1, `Expected one successful booking, received ${successful.length}`);
  assert.equal(conflicts.length, clients - 1, `Expected ${clients - 1} conflicts, received ${conflicts.length}`);
  assert.equal(unexpected.length, 0, `Unexpected errors: ${unexpected.map((item) => item.error?.message).join(", ")}`);

  const winner = successful[0];
  const publicId = winner.response.data[0].booking.public_id;
  await api.post(`/bookings/${publicId}/cancel`, {
    telegramId: winner.telegramId,
    body: {reason: `Stage 10 load cleanup ${runId}`},
    idempotencyKey: `load:${runId}:${winner.telegramId}:cancel`,
  });

  const p50 = percentile(durations, 0.5);
  const p95 = percentile(durations, 0.95);
  const max = Math.max(...durations);
  assert.ok(p95 <= maxP95, `p95 ${p95.toFixed(0)}ms exceeds ${maxP95}ms`);

  console.log(
    `[ok] Booking race: requests=${clients}, result=1x201+${conflicts.length}x409, ` +
    `p50=${p50.toFixed(0)}ms, p95=${p95.toFixed(0)}ms, max=${max.toFixed(0)}ms`
  );
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
