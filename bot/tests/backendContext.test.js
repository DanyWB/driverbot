const assert = require("node:assert/strict");
const test = require("node:test");

test("synchronizes a Telegram customer once per configured session window", async () => {
  const clientModule = require("../services/botApiClient");
  const gatewayModule = require("../services/laravelGateway");
  const originalClientFactory = clientModule.getBotApiClient;
  const originalSync = gatewayModule.syncTelegramUser;
  const originalTtl = process.env.BOT_CUSTOMER_SYNC_TTL_SECONDS;
  let syncCalls = 0;
  let nextCalls = 0;

  clientModule.getBotApiClient = () => ({name: "fake-client"});
  gatewayModule.syncTelegramUser = async (user) => {
    syncCalls += 1;
    return {telegram_id: user.id};
  };
  process.env.BOT_CUSTOMER_SYNC_TTL_SECONDS = "60";
  delete require.cache[require.resolve("../middlewares/backendContext")];

  try {
    const middleware = require("../middlewares/backendContext");
    const ctx = {from: {id: 123}, session: {}};
    const next = async () => {
      nextCalls += 1;
    };

    await middleware(ctx, next);
    await middleware(ctx, next);
    assert.equal(syncCalls, 1);
    assert.equal(nextCalls, 2);
    assert.equal(ctx.backend.name, "fake-client");

    ctx.session.backendCustomerSyncedAt = 0;
    await middleware(ctx, next);
    assert.equal(syncCalls, 2);

    await middleware({
      from: {id: 456},
      message: {text: "/start"},
      session: {},
    }, next);
    assert.equal(syncCalls, 2);

    await middleware({
      from: {id: 457},
      message: {text: "/bind ABCD-EFGH"},
      session: {},
    }, next);
    await middleware({
      from: {id: 458},
      message: {text: "/bind@DrivePhanganBot ABCD-EFGH"},
      session: {},
    }, next);
    assert.equal(syncCalls, 2);
    assert.equal(nextCalls, 6);
  } finally {
    clientModule.getBotApiClient = originalClientFactory;
    gatewayModule.syncTelegramUser = originalSync;
    if (originalTtl === undefined) delete process.env.BOT_CUSTOMER_SYNC_TTL_SECONDS;
    else process.env.BOT_CUSTOMER_SYNC_TTL_SECONDS = originalTtl;
    delete require.cache[require.resolve("../middlewares/backendContext")];
  }
});
