const test = require("node:test");
const assert = require("node:assert/strict");
const {inspectEnvironment} = require("../scripts/release-preflight");

test("accepts a minimal production Laravel-mode bot environment", () => {
  const checks = inspectEnvironment({
    NODE_ENV: "production",
    BOT_DATA_MODE: "laravel",
    BOT_TOKEN: `123456789:${"a".repeat(35)}`,
    BOT_API_URL: "https://admin.drivephangan.com/api/v1/bot",
    BOT_API_TOKEN: "service-token".repeat(4),
    REDIS_URL: "redis://127.0.0.1:6379/2",
  });

  assert.equal(checks.every((check) => check.passed), true);
});

test("rejects insecure endpoints, placeholders and legacy credentials", () => {
  const checks = inspectEnvironment({
    NODE_ENV: "development",
    BOT_DATA_MODE: "legacy",
    BOT_TOKEN: "CHANGE_ME",
    BOT_API_URL: "http://127.0.0.1:8000/api/v1/bot",
    BOT_API_TOKEN: "short",
    REDIS_URL: "",
    PG_HOST: "127.0.0.1",
  });

  assert.equal(checks.every((check) => !check.passed), true);
});
