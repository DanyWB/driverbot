const {getBotApiClient, idempotencyKey} = require("../services/botApiClient");
const {positiveInteger} = require("../config/runtime");
const {syncTelegramUser} = require("../services/laravelGateway");

module.exports = async (ctx, next) => {
  ctx.backend = getBotApiClient();
  ctx.backendIdempotencyKey = (operation) => idempotencyKey(ctx, operation);

  const skipsCustomerSync = /^\/(?:start|bind)(?:@[A-Za-z0-9_]+)?(?:\s|$)/.test(
    ctx.message?.text || ""
  );

  if (ctx.from && !skipsCustomerSync) {
    const ttlMs = positiveInteger("BOT_CUSTOMER_SYNC_TTL_SECONDS", 86400) * 1000;
    const lastSyncedAt = Number(ctx.session?.backendCustomerSyncedAt || 0);

    if (!Number.isFinite(lastSyncedAt) || Date.now() - lastSyncedAt >= ttlMs) {
      ctx.backendCustomer = await syncTelegramUser(ctx.from);
      ctx.session.backendCustomerSyncedAt = Date.now();
    }
  }

  return next();
};
