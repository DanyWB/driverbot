const test = require("node:test");
const assert = require("node:assert/strict");
const cart = require("../services/sessionCartService");

function context() {
  return {from: {id: 100500}, session: {}};
}

function booking() {
  return {
    selectedBikeId: 7,
    startDate: "2026-08-10",
    endDate: "2026-08-16",
    startTime: "10:00",
    endTime: "09:00",
    helmets: 2,
    deliveryRequired: true,
    deliveryAddress: "Thong Sala Pier",
    notes: "Call first",
  };
}

test("keeps a multi-vehicle cart in session and produces the Laravel batch contract", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  cart.add(
    ctx,
    {...booking(), selectedBikeId: 8},
    {id: 8, name: "Yamaha NMAX"},
    {final_total: 900, currency: "THB"}
  );

  const items = cart.toApiItems(ctx);
  assert.equal(items.length, 2);
  assert.deepEqual(items[0], {
    client_reference: items[0].client_reference,
    vehicle_id: 7,
    starts_on: "2026-08-10",
    ends_on: "2026-08-16",
    pickup_time: "10:00",
    return_time: "09:00",
    helmets_quantity: 2,
    delivery_required: true,
    delivery_address: "Thong Sala Pier",
    client_comment: "Call first",
  });
});

test("uses a stable confirmation key for retries and rotates it after cart changes", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  const first = cart.confirmationKey(ctx);
  assert.equal(cart.confirmationKey(ctx), first);

  const options = {...booking(), notes: "Changed"};
  cart.applyOptions(ctx, options);
  assert.notEqual(cart.confirmationKey(ctx), first);
});

test("does not duplicate the same vehicle in one cart", () => {
  const ctx = context();
  const first = cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  const second = cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  assert.equal(first.created, true);
  assert.equal(second.created, false);
  assert.equal(cart.getCart(ctx).length, 1);
});

test("binds consent to a terms version and clears it with the cart", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  cart.markTermsAccepted(ctx, "2026-07-22");

  assert.equal(ctx.session.acceptTerms, true);
  assert.equal(ctx.session.acceptedTermsVersion, "2026-07-22");
  assert.equal(cart.getCart(ctx)[0].accept_terms, true);

  cart.clear(ctx);
  assert.equal(ctx.session.acceptTerms, null);
  assert.equal(ctx.session.acceptedTermsVersion, null);
  assert.deepEqual(cart.getCart(ctx), []);
});

test("applies authoritative quote updates atomically", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  cart.add(
    ctx,
    {...booking(), selectedBikeId: 8},
    {id: 8, name: "Yamaha NMAX"},
    {final_total: 900, currency: "THB"}
  );

  assert.throws(
    () => cart.applyAuthoritativeQuotes(ctx, [
      {vehicle_id: 7, final_total: 800, currency: "THB"},
      {vehicle_id: 8, final_total: null, currency: "THB"},
    ]),
    /Missing authoritative quote for vehicle 8/
  );
  assert.deepEqual(
    cart.getCart(ctx).map((item) => item.total_price),
    [700, 900]
  );
});

test("matches authoritative quotes by cart position even for duplicate vehicle ids", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  ctx.session.bookingCart.push({
    ...ctx.session.bookingCart[0],
    id: "second-item",
    client_reference: "second-reference",
    start_date: "2026-09-01",
    end_date: "2026-09-03",
    total_price: 900,
  });

  assert.equal(cart.applyAuthoritativeQuotes(ctx, [
    {vehicle_id: 7, final_total: 800, currency: "THB"},
    {vehicle_id: 7, final_total: 1100, currency: "THB"},
  ]), true);
  assert.deepEqual(
    cart.getCart(ctx).map((item) => item.total_price),
    [800, 1100]
  );
});

test("normalizes equivalent money values and rotates for an actual currency change", () => {
  const ctx = context();
  cart.add(ctx, booking(), {id: 7, name: "Honda Click"}, {final_total: 700, currency: "THB"});
  const key = cart.confirmationKey(ctx);

  assert.equal(cart.applyAuthoritativeQuotes(ctx, [
    {vehicle_id: 7, final_total: "700.00", currency: "thb"},
  ]), false);
  assert.equal(cart.confirmationKey(ctx), key);
  assert.equal(cart.getCart(ctx)[0].total_price, 700);

  assert.equal(cart.applyAuthoritativeQuotes(ctx, [
    {vehicle_id: 7, final_total: "700.0", currency: "USD"},
  ]), true);
  assert.notEqual(cart.confirmationKey(ctx), key);
  assert.equal(cart.getCart(ctx)[0].currency, "USD");
});
