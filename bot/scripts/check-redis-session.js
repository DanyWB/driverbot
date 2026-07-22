const assert = require("node:assert/strict");
const {createClient} = require("redis");

process.env.REDIS_URL ||= "redis://127.0.0.1:6379/1";
process.env.BOT_SESSION_PREFIX = `drive-phangan:e2e:${Date.now()}`;
process.env.BOT_SESSION_LOCK_TTL_MS = "120";
process.env.BOT_SESSION_LOCK_WAIT_MS = "2000";

const sessionStorage = require("../middlewares/redisSessionStorage");
const userId = 900000001;

async function update(delay) {
  const ctx = {from: {id: userId}};
  await sessionStorage(ctx, async () => {
    const current = ctx.session.counter || 0;
    await new Promise((resolve) => setTimeout(resolve, delay));
    ctx.session.counter = current + 1;
  });
}

async function main() {
  await Promise.all([update(320), update(10)]);

  const ctx = {from: {id: userId}};
  await sessionStorage(ctx, async () => {
    assert.equal(ctx.session.counter, 2);
  });

  const redis = createClient({url: process.env.REDIS_URL});
  redis.on("error", (error) => console.error("Redis cleanup error:", error.message));
  await redis.connect();
  await redis.del(`${process.env.BOT_SESSION_PREFIX}:${userId}`);
  redis.destroy();
  await sessionStorage.closeRedisSession();
  console.log("[ok] Redis session renews its lock, serializes concurrent updates and persists state");
}

main().catch(async (error) => {
  console.error(error);
  await sessionStorage.closeRedisSession();
  process.exitCode = 1;
});
