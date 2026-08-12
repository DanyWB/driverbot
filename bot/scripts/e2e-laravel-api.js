const assert = require("node:assert/strict");
const {randomUUID} = require("crypto");
require("dotenv").config();
const {BotApiClient} = require("../services/botApiClient");

function futureDate(days) {
  const date = new Date(Date.now() + days * 24 * 60 * 60 * 1000);
  return date.toISOString().slice(0, 10);
}

async function main() {
  const client = new BotApiClient();
  const runId = `${Date.now()}-${randomUUID().slice(0, 8)}`;
  const telegramId = String(process.env.E2E_TELEGRAM_ID || `9${Date.now().toString().slice(-9)}`);
  const headers = {telegramId};

  const configuration = await client.get("/configuration");
  assert.ok(configuration.data.terms_version);

  const syncBody = {
    telegram_id: telegramId,
    username: `e2e_${telegramId}`,
    first_name: "Stage8",
    locale: "en",
  };
  const synced = await client.post("/customers/sync", {
    body: syncBody,
    idempotencyKey: `e2e:${runId}:sync`,
  });
  assert.equal(synced.data.telegram.id, telegramId);

  const profile = await client.patch("/customers/me", {
    ...headers,
    body: {name: "Stage Eight E2E", locale: "en", phone: "+66900000000"},
    idempotencyKey: `e2e:${runId}:profile`,
  });
  assert.equal(profile.data.profile_complete.phone, true);

  const resynced = await client.post("/customers/sync", {
    body: syncBody,
    idempotencyKey: `e2e:${runId}:resync`,
  });
  assert.equal(resynced.data.name, "Stage Eight E2E");
  assert.equal(resynced.data.phone, "+66900000000");

  const categories = await client.get("/categories");
  assert.ok(categories.data.length > 0);
  const vehicles = await client.get("/vehicles");
  assert.ok(vehicles.data.length > 0);
  const vehicle = vehicles.data[0];
  const startsOn = futureDate(60);
  const endsOn = futureDate(66);

  const availability = await client.get(`/vehicles/${vehicle.id}/availability`, {
    query: {start_date: startsOn, end_date: endsOn},
  });
  assert.equal(availability.data.vehicle_id, vehicle.id);

  const quote = await client.post("/quotes", {
    body: {vehicle_id: vehicle.id, starts_on: startsOn, ends_on: endsOn},
    safeRetry: true,
  });
  assert.ok(quote.data.final_total > 0);
  assert.equal(quote.data.available, true);

  const bookingBody = {
    terms_accepted: true,
    terms_version: configuration.data.terms_version,
    items: [{
      client_reference: `e2e-${runId}`,
      vehicle_id: vehicle.id,
      starts_on: startsOn,
      ends_on: endsOn,
      pickup_time: "10:00",
      return_time: "09:00",
      helmets_quantity: 2,
      delivery_required: true,
      delivery_address: "Thong Sala Pier",
      client_comment: "Stage 8 HTTP E2E",
    }],
  };
  const bookingKey = `e2e:${runId}:booking`;
  const created = await client.post("/bookings", {
    ...headers,
    body: bookingBody,
    idempotencyKey: bookingKey,
  });
  assert.equal(created.data.length, 1);
  assert.equal(created.meta.idempotency_replayed, false);
  const publicId = created.data[0].booking.public_id;

  const replayed = await client.post("/bookings", {
    ...headers,
    body: bookingBody,
    idempotencyKey: bookingKey,
  });
  assert.equal(replayed.meta.idempotency_replayed, true);
  assert.equal(replayed.data[0].booking.public_id, publicId);

  const listed = await client.get("/customers/me/bookings", {
    ...headers,
    query: {scope: "current"},
  });
  assert.ok(listed.data.some((booking) => booking.public_id === publicId));

  const png = Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=",
    "base64"
  );
  const formData = new FormData();
  formData.set("type", "passport");
  formData.set("document", new Blob([png], {type: "image/png"}), "passport.png");
  const document = await client.post("/customers/me/documents", {
    ...headers,
    formData,
    idempotencyKey: `e2e:${runId}:document`,
  });
  assert.equal(document.data.type, "passport");

  const cancelled = await client.post(`/bookings/${publicId}/cancel`, {
    ...headers,
    body: {reason: "Stage 8 E2E cleanup"},
    idempotencyKey: `e2e:${runId}:cancel`,
  });
  assert.equal(cancelled.data.status, "cancelled_by_client");

  console.log(`[ok] Laravel HTTP E2E: customer=${telegramId}, booking=${publicId}`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
