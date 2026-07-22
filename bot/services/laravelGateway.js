const {createHash, randomUUID} = require("crypto");
const {getBotApiClient, idempotencyKey} = require("./botApiClient");

function stableKey(prefix, telegramId, value) {
  const hash = createHash("sha256").update(JSON.stringify(value)).digest("hex").slice(0, 24);
  return `telegram:${telegramId}:${prefix}:${hash}`;
}

function mutationKey(prefix, telegramId) {
  return `telegram:${telegramId}:${prefix}:${randomUUID()}`;
}

function telegramLocale(locale) {
  const base = String(locale || "").trim().toLowerCase().split("-")[0];
  if (base === "uk") return "ua";
  return ["ru", "en", "ua"].includes(base) ? base : null;
}

function api() {
  return getBotApiClient();
}

function normalizeVehicle(vehicle) {
  if (!vehicle) return null;
  return {
    ...vehicle,
    vehicle_type: vehicle.type,
    category_id: vehicle.category?.id || null,
    category_name: vehicle.category?.name || null,
    image_url: vehicle.primary_photo?.thumbnail_url || vehicle.primary_photo?.url || null,
  };
}

function profileAsLegacyUser(profile) {
  if (!profile) return null;
  return {
    id: profile.id,
    telegram_id: profile.telegram?.id,
    telegram_name: profile.telegram?.username?.replace(/^@/, "") || null,
    name: profile.name,
    phone: profile.phone,
    lang: profile.locale,
    passport_photo_file_id: profile.passport?.has_document ? "stored-in-laravel" : null,
    meta: {passport_number: profile.passport?.number || null},
    profile_complete: profile.profile_complete,
    is_admin: false,
  };
}

function dateTime(date, time) {
  return time ? `${date}T${time}:00` : null;
}

function normalizeBooking(booking) {
  if (!booking) return null;
  return {
    ...booking,
    id: booking.public_id,
    booking_public_id: booking.public_id,
    bike_id: booking.vehicle?.id,
    bike_name: booking.vehicle?.name,
    bike_desc: booking.vehicle?.description,
    start_date: booking.starts_on,
    end_date: booking.ends_on,
    start_at: dateTime(booking.starts_on, booking.pickup_time),
    end_at: dateTime(booking.ends_on, booking.return_time),
    total_price: booking.price?.final_total ?? null,
    currency: booking.price?.currency || "THB",
    helmets_qty: booking.options?.helmets_quantity || 0,
    delivery_required: Boolean(booking.options?.delivery_required),
    delivery_address: booking.options?.delivery_address || null,
    comment: booking.options?.client_comment || null,
    can_cancel: Boolean(booking.can_cancel),
    cancellation: booking.cancellation || null,
  };
}

async function syncTelegramUser(user) {
  const body = {
    telegram_id: String(user.id),
    username: user.username || null,
    first_name: user.first_name || null,
    last_name: user.last_name || null,
    locale: telegramLocale(user.language_code),
  };
  const payload = await api().post("/customers/sync", {
    body,
    idempotencyKey: mutationKey("sync", user.id),
  });
  return profileAsLegacyUser(payload.data);
}

async function getProfile(telegramId) {
  const payload = await api().get("/customers/me", {telegramId});
  return profileAsLegacyUser(payload.data);
}

async function updateProfile(telegramId, changes) {
  const payload = await api().patch("/customers/me", {
    telegramId,
    body: changes,
    idempotencyKey: mutationKey("profile", telegramId),
  });
  return profileAsLegacyUser(payload.data);
}

async function uploadPassport(telegramId, {buffer, filename, mimeType}) {
  const formData = new FormData();
  formData.set("type", "passport");
  formData.set("document", new Blob([buffer], {type: mimeType || "image/jpeg"}), filename);
  const fileHash = createHash("sha256").update(buffer).digest("hex").slice(0, 32);
  const payload = await api().post("/customers/me/documents", {
    telegramId,
    formData,
    idempotencyKey: `telegram:${telegramId}:passport:${fileHash}`,
  });
  return payload.data;
}

let configurationCache;
let configurationExpiresAt = 0;

async function getConfiguration({refresh = false} = {}) {
  if (!refresh && configurationCache && Date.now() < configurationExpiresAt) {
    return configurationCache;
  }
  const payload = await api().get("/configuration");
  configurationCache = payload.data;
  configurationExpiresAt = Date.now() + 5 * 60 * 1000;
  return configurationCache;
}

async function listCategories() {
  const payload = await api().get("/categories");
  return payload.data || [];
}

async function listVehicles(filters = {}) {
  const payload = await api().get("/vehicles", {query: filters});
  return (payload.data || []).map(normalizeVehicle);
}

async function listAvailableVehicles(filters) {
  const payload = await api().get("/vehicles/available", {
    query: {
      start_date: filters.startDate,
      end_date: filters.endDate,
      category_id: filters.categoryId,
      type: filters.vehicleType,
    },
  });
  return (payload.data || []).map(normalizeVehicle);
}

async function getVehicle(vehicleId) {
  const payload = await api().get(`/vehicles/${vehicleId}`);
  return normalizeVehicle(payload.data);
}

async function unavailableDates(vehicleId, startDate, endDate) {
  const payload = await api().get(`/vehicles/${vehicleId}/availability`, {
    query: {start_date: startDate, end_date: endDate},
  });
  return payload.data?.unavailable_dates || [];
}

async function quote(vehicleId, startDate, endDate) {
  const payload = await api().post("/quotes", {
    body: {vehicle_id: vehicleId, starts_on: startDate, ends_on: endDate},
    safeRetry: true,
  });
  return payload.data;
}

async function createBookings(ctx, items, confirmationKey) {
  const termsVersion = String(ctx.session?.acceptedTermsVersion || "");
  if (!termsVersion) {
    throw new Error("Accepted rental terms version is required");
  }
  const payload = await api().post("/bookings", {
    telegramId: ctx.from.id,
    idempotencyKey: confirmationKey || idempotencyKey(ctx, "bookings-create"),
    body: {
      terms_accepted: true,
      terms_version: termsVersion,
      items,
    },
  });
  return (payload.data || []).map((entry) => ({
    client_reference: entry.client_reference,
    booking: normalizeBooking(entry.booking),
  }));
}

async function listBookings(telegramId, scope, {limit = 50, offset = 0} = {}) {
  const payload = await api().get("/customers/me/bookings", {
    telegramId,
    query: {scope, limit, offset},
  });
  return (payload.data || []).map(normalizeBooking);
}

async function getBooking(telegramId, publicId) {
  const payload = await api().get(`/bookings/${publicId}`, {telegramId});
  return normalizeBooking(payload.data);
}

async function cancelBooking(ctx, publicId, reason = null) {
  const body = reason ? {reason} : {};
  const payload = await api().post(`/bookings/${publicId}/cancel`, {
    telegramId: ctx.from.id,
    idempotencyKey: stableKey(`booking-cancel:${publicId}`, ctx.from.id, body),
    body,
  });
  return normalizeBooking(payload.data);
}

module.exports = {
  cancelBooking,
  createBookings,
  getBooking,
  getConfiguration,
  getProfile,
  getVehicle,
  listAvailableVehicles,
  listBookings,
  listCategories,
  listVehicles,
  normalizeBooking,
  normalizeVehicle,
  profileAsLegacyUser,
  syncTelegramUser,
  unavailableDates,
  updateProfile,
  uploadPassport,
  quote,
};
