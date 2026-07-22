const assert = require("node:assert/strict");

process.env.BOT_DATA_MODE = "laravel";
process.env.BOT_TOKEN ||= "123456:test-token";
process.env.BOT_API_URL ||= "http://127.0.0.1:8000/api/v1/bot";
process.env.BOT_API_TOKEN ||= "test-service-token";
process.env.REDIS_URL ||= "redis://127.0.0.1:6379/1";

const db = require("../connect");
assert.throws(() => db("users"), /Direct database access is disabled/);
require("../bot");

console.log("[ok] Laravel mode loads without PostgreSQL credentials or legacy schedulers");
