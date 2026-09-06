const {randomUUID} = require("crypto");

function getCart(ctx) {
  if (!Array.isArray(ctx.session.bookingCart)) ctx.session.bookingCart = [];
  return ctx.session.bookingCart;
}

function rotateConfirmationNonce(ctx) {
  ctx.session.bookingCartConfirmationNonce = randomUUID();
  ctx.session.bookingCartConfirmationAttemptKey = null;
}

function confirmationKey(ctx) {
  ctx.session.bookingCartConfirmationNonce ||= randomUUID();
  return `telegram:${ctx.from.id}:cart:${ctx.session.bookingCartConfirmationNonce}`;
}

function hasConfirmationAttempt(ctx, key) {
  return ctx.session.bookingCartConfirmationAttemptKey === key;
}

function markConfirmationAttempt(ctx, key) {
  ctx.session.bookingCartConfirmationAttemptKey = key;
}

function clearConfirmationAttempt(ctx) {
  ctx.session.bookingCartConfirmationAttemptKey = null;
}

function dateTime(date, time) {
  return time ? `${date}T${time}:00` : null;
}

function add(ctx, booking, vehicle, quote) {
  const cart = getCart(ctx);
  const existing = cart.find((item) => item.bike_id === vehicle.id);
  if (existing) return {created: false, rental: existing, vehicle, bike: vehicle};

  const item = {
    id: randomUUID(),
    client_reference: randomUUID(),
    bike_id: vehicle.id,
    name: vehicle.name,
    bike_name: vehicle.name,
    vehicle,
    start_date: booking.startDate,
    end_date: booking.endDate,
    start_at: dateTime(booking.startDate, booking.startTime),
    end_at: dateTime(booking.endDate, booking.endTime),
    start_time: booking.startTime || null,
    end_time: booking.endTime || null,
    total_price: quote.final_total,
    currency: quote.currency,
    helmets_qty: booking.helmets || 0,
    delivery_required: Boolean(booking.deliveryRequired),
    delivery_address: booking.deliveryAddress || null,
    comment: booking.notes || null,
    accept_terms: Boolean(ctx.session.acceptTerms),
  };
  cart.push(item);
  rotateConfirmationNonce(ctx);
  return {created: true, rental: item, vehicle, bike: vehicle};
}

function remove(ctx, id) {
  const cart = getCart(ctx);
  const index = cart.findIndex((item) => item.id === id);
  if (index < 0) return false;
  cart.splice(index, 1);
  rotateConfirmationNonce(ctx);
  return true;
}

function clear(ctx) {
  ctx.session.bookingCart = [];
  ctx.session.bookingCartConfirmationNonce = null;
  clearConfirmationAttempt(ctx);
  clearTermsAcceptance(ctx);
}

function markTermsAccepted(ctx, termsVersion) {
  if (!termsVersion) throw new Error("Rental terms version is required");
  ctx.session.acceptTerms = true;
  ctx.session.acceptedTermsVersion = String(termsVersion);
  getCart(ctx).forEach((item) => {
    item.accept_terms = true;
  });
}

function clearTermsAcceptance(ctx) {
  ctx.session.acceptTerms = null;
  ctx.session.acceptedTermsVersion = null;
  getCart(ctx).forEach((item) => {
    item.accept_terms = false;
  });
}

function applyOptions(ctx, booking) {
  getCart(ctx).forEach((item) => {
    item.start_time = booking.startTime || null;
    item.end_time = booking.endTime || null;
    item.start_at = dateTime(item.start_date, item.start_time);
    item.end_at = dateTime(item.end_date, item.end_time);
    item.helmets_qty = booking.helmets || 0;
    item.delivery_required = Boolean(booking.deliveryRequired);
    item.delivery_address = booking.deliveryAddress || null;
    item.comment = booking.notes || null;
  });
  rotateConfirmationNonce(ctx);
}

function loadOptionsIntoBooking(ctx, booking) {
  const item = getCart(ctx).at(-1);
  if (!item) return null;
  booking.startDate = item.start_date;
  booking.endDate = item.end_date;
  booking.startTime = item.start_time;
  booking.endTime = item.end_time;
  booking.helmets = item.helmets_qty;
  booking.deliveryRequired = item.delivery_required;
  booking.deliveryAddress = item.delivery_address;
  booking.notes = item.comment;
  booking.timeSource = null;
  return item;
}

function normalizeMonetaryValue(value) {
  const input = String(value ?? "").trim();
  const match = /^([+-]?)(\d+)(?:\.(\d+))?$/.exec(input);
  if (!match) return input;

  const integer = match[2].replace(/^0+(?=\d)/, "");
  const fraction = String(match[3] || "").replace(/0+$/, "");
  const negative = match[1] === "-" && (integer !== "0" || fraction !== "");
  return `${negative ? "-" : ""}${integer}${fraction ? `.${fraction}` : ""}`;
}

function applyAuthoritativeQuotes(ctx, quotes) {
  const cart = getCart(ctx);
  if (quotes.length !== cart.length) {
    throw new Error("Authoritative quote count does not match the booking cart");
  }

  const updates = cart.map((item, index) => {
    const quote = quotes[index];
    if (!quote || quote.final_total == null || !String(quote.currency || "").trim()) {
      throw new Error(`Missing authoritative quote for vehicle ${item.bike_id}`);
    }
    if (String(quote.vehicle_id) !== String(item.bike_id)) {
      throw new Error(`Authoritative quote vehicle mismatch at cart position ${index}`);
    }

    const totalChanged =
      normalizeMonetaryValue(item.total_price) !==
      normalizeMonetaryValue(quote.final_total);
    const currencyChanged =
      String(item.currency || "").trim().toUpperCase() !==
      String(quote.currency).trim().toUpperCase();
    return {
      item,
      quote,
      changed: totalChanged || currencyChanged,
    };
  });

  const changed = updates.some((update) => update.changed);
  if (!changed) return false;

  for (const {item, quote, changed: itemChanged} of updates) {
    if (!itemChanged) continue;
    item.total_price = quote.final_total;
    item.currency = String(quote.currency).trim();
  }

  rotateConfirmationNonce(ctx);
  return true;
}

function toApiItems(ctx) {
  return getCart(ctx).map((item) => ({
    client_reference: item.client_reference,
    vehicle_id: item.bike_id,
    starts_on: item.start_date,
    ends_on: item.end_date,
    pickup_time: item.start_time,
    return_time: item.end_time,
    helmets_quantity: item.helmets_qty,
    delivery_required: item.delivery_required,
    delivery_address: item.delivery_address,
    client_comment: item.comment,
  }));
}

module.exports = {
  add,
  applyAuthoritativeQuotes,
  applyOptions,
  clear,
  clearConfirmationAttempt,
  clearTermsAcceptance,
  confirmationKey,
  getCart,
  hasConfirmationAttempt,
  loadOptionsIntoBooking,
  markTermsAccepted,
  markConfirmationAttempt,
  remove,
  toApiItems,
};
