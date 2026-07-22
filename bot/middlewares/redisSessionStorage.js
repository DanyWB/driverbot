const {randomUUID} = require("crypto");
const {createClient} = require("redis");
const {positiveInteger} = require("../config/runtime");

let client;

function redisClient() {
  if (!client) {
    client = createClient({url: process.env.REDIS_URL});
    client.on("error", (error) => console.error("Redis session error:", error.message));
  }
  return client;
}

async function connectedClient() {
  const redis = redisClient();
  if (!redis.isOpen) await redis.connect();
  return redis;
}

async function acquireLock(redis, key, lockTtlMs) {
  const token = randomUUID();
  const waitMs = positiveInteger("BOT_SESSION_LOCK_WAIT_MS", 5000);
  const deadline = Date.now() + waitMs;

  do {
    const acquired = await redis.set(key, token, {NX: true, PX: lockTtlMs});
    if (acquired === "OK") return token;
    await new Promise((resolve) => setTimeout(resolve, 40));
  } while (Date.now() < deadline);

  throw new Error("Could not acquire Telegram session lock");
}

async function ownsLock(redis, key, token) {
  return (await redis.get(key)) === token;
}

async function extendLock(redis, key, token, lockTtlMs) {
  return Number(await redis.eval(
    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) else return 0 end",
    {keys: [key], arguments: [token, String(lockTtlMs)]}
  )) === 1;
}

function keepLockAlive(redis, key, token, lockTtlMs) {
  const intervalMs = Math.max(25, Math.floor(lockTtlMs / 3));
  let timer;
  let pending = Promise.resolve();
  let stopped = false;
  let lost = false;

  const schedule = () => {
    if (stopped || lost) return;
    timer = setTimeout(() => {
      pending = extendLock(redis, key, token, lockTtlMs)
        .then((extended) => {
          if (!extended) lost = true;
        })
        .catch((error) => console.error("Redis session lock renewal error:", error.message))
        .finally(schedule);
    }, intervalMs);
    timer.unref?.();
  };

  schedule();

  return {
    get lost() {
      return lost;
    },
    async stop() {
      stopped = true;
      clearTimeout(timer);
      await pending;
    },
  };
}

async function releaseLock(redis, key, token) {
  await redis.eval(
    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
    {keys: [key], arguments: [token]}
  );
}

module.exports = async function redisSessionStorage(ctx, next) {
  if (!ctx.from) return next();

  const redis = await connectedClient();
  const prefix = process.env.BOT_SESSION_PREFIX || "drive-phangan:bot:session:v1";
  const sessionKey = `${prefix}:${ctx.from.id}`;
  const lockKey = `${sessionKey}:lock`;
  const lockTtlMs = positiveInteger("BOT_SESSION_LOCK_TTL_MS", 30000);
  const token = await acquireLock(redis, lockKey, lockTtlMs);
  const lease = keepLockAlive(redis, lockKey, token, lockTtlMs);
  let completed = false;

  try {
    const stored = await redis.get(sessionKey);
    try {
      ctx.session = stored ? JSON.parse(stored) : {};
    } catch (error) {
      console.error(`Invalid session JSON for Telegram user ${ctx.from.id}; resetting it.`);
      ctx.session = {};
    }

    await next();
    completed = true;
  } finally {
    try {
      if (completed) {
        if (lease.lost || !(await ownsLock(redis, lockKey, token))) {
          throw new Error("Telegram session lock was lost before the session could be saved");
        }
        if (ctx.session == null) {
          await redis.del(sessionKey);
        } else {
          const ttlSeconds = positiveInteger("BOT_SESSION_TTL_SECONDS", 2592000);
          await redis.set(sessionKey, JSON.stringify(ctx.session), {EX: ttlSeconds});
        }
      }
    } finally {
      await lease.stop();
      await releaseLock(redis, lockKey, token);
    }
  }
};

module.exports.closeRedisSession = async function closeRedisSession() {
  if (client?.isOpen) client.destroy();
  client = undefined;
};
