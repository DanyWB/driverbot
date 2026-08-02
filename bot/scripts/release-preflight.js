const assert = require("node:assert/strict");
const {createClient} = require("redis");
require("dotenv").config();
const {BotApiClient} = require("../services/botApiClient");

function inspectEnvironment(env = process.env) {
  const checks = [];
  const add = (id, passed, message) => checks.push({id, passed, message});
  const botToken = String(env.BOT_TOKEN || "").trim();
  const apiUrl = String(env.BOT_API_URL || "").trim();
  const apiToken = String(env.BOT_API_TOKEN || "").trim();
  const redisUrl = String(env.REDIS_URL || "").trim();

  add("node.environment", env.NODE_ENV === "production", "NODE_ENV must be production.");
  add("data.mode", env.BOT_DATA_MODE === "laravel", "BOT_DATA_MODE must be laravel.");
  add(
    "telegram.token",
    /^[0-9]{6,12}:[A-Za-z0-9_-]{30,}$/.test(botToken),
    "BOT_TOKEN is missing or malformed."
  );
  add(
    "laravel.url",
    /^https:\/\//i.test(apiUrl) && !apiUrl.includes("CHANGE_ME") && !/\.example\.com(?:\/|$)/i.test(apiUrl),
    "BOT_API_URL must use HTTPS."
  );
  add(
    "laravel.token",
    apiToken.length >= 32 && !apiToken.includes("CHANGE_ME"),
    "BOT_API_TOKEN is missing or too short."
  );
  add(
    "redis.url",
    /^(?:redis|rediss):\/\//i.test(redisUrl),
    "REDIS_URL must be a Redis connection URL."
  );

  const legacyKeys = ["DATABASE_URL", "PG_HOST", "PG_USER", "PG_PASSWORD", "PG_DATABASE"];
  const configuredLegacyKeys = legacyKeys.filter((key) => String(env[key] || "").trim() !== "");
  add(
    "legacy.credentials",
    configuredLegacyKeys.length === 0,
    `Remove unused legacy database settings: ${configuredLegacyKeys.join(", ") || "none"}.`
  );

  return checks;
}

async function onlineChecks() {
  const api = new BotApiClient();
  const [configuration, categories] = await Promise.all([
    api.get("/configuration"),
    api.get("/categories"),
  ]);
  assert.ok(configuration.data.terms_version);
  assert.ok(Array.isArray(categories.data));

  const redis = createClient({url: process.env.REDIS_URL});
  await redis.connect();
  try {
    assert.equal(await redis.ping(), "PONG");
  } finally {
    await redis.quit();
  }

  const response = await fetch(`https://api.telegram.org/bot${process.env.BOT_TOKEN}/getMe`, {
    signal: AbortSignal.timeout(7000),
  });
  const payload = await response.json();
  assert.equal(response.ok, true);
  assert.equal(payload.ok, true);

  console.log(`[ok] Online integrations: Laravel, Redis, Telegram @${payload.result.username}`);
}

async function main() {
  const checks = inspectEnvironment();
  checks.forEach((check) => console.log(`[${check.passed ? "ok" : "fail"}] ${check.id}: ${check.message}`));

  if (checks.some((check) => !check.passed)) {
    process.exitCode = 1;
    return;
  }

  if (process.argv.includes("--online")) await onlineChecks();
}

if (require.main === module) {
  main().catch((error) => {
    console.error(`[fail] Bot release preflight: ${error.message}`);
    process.exitCode = 1;
  });
}

module.exports = {inspectEnvironment};
