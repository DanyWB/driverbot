const test = require("node:test");
const assert = require("node:assert/strict");
const {
  dataMode,
  requireLaravelConfig,
} = require("../config/runtime");
const {main: checkLaravelApi} = require("../scripts/check-laravel-api");

function laravelEnvironment(overrides = {}) {
  return {
    NODE_ENV: "production",
    BOT_DATA_MODE: "laravel",
    BOT_API_URL: "https://backend.test/api/v1/bot",
    BOT_API_TOKEN: "test-service-token",
    REDIS_URL: "redis://127.0.0.1:6379/1",
    ...overrides,
  };
}

test("production defaults to Laravel and fails closed when its configuration is missing", () => {
  assert.equal(dataMode({NODE_ENV: "production"}), "laravel");
  assert.equal(dataMode({NODE_ENV: " Production "}), "laravel");
  assert.throws(
    () => requireLaravelConfig({NODE_ENV: "production"}),
    /Missing Laravel bot configuration/
  );
  assert.equal(dataMode({NODE_ENV: "development"}), "legacy");
});

test("Laravel API check rejects unset or legacy mode before constructing a client", async () => {
  for (const mode of [undefined, "legacy"]) {
    let constructed = false;
    class UnexpectedClient {
      constructor() {
        constructed = true;
      }
    }

    const env = laravelEnvironment();
    if (mode === undefined) delete env.BOT_DATA_MODE;
    else env.BOT_DATA_MODE = mode;

    await assert.rejects(
      checkLaravelApi({env, Client: UnexpectedClient}),
      /BOT_DATA_MODE must be explicitly set to laravel/
    );
    assert.equal(constructed, false);
  }
});

test("Laravel API check reaches both read endpoints with an explicit valid mode", async () => {
  const paths = [];
  class FakeClient {
    async get(path) {
      paths.push(path);
      if (path === "/configuration") {
        return {data: {timezone: "Asia/Bangkok", terms_version: "2026-09-02"}};
      }
      return {data: []};
    }
  }

  await checkLaravelApi({env: laravelEnvironment(), Client: FakeClient});
  assert.deepEqual(paths.sort(), ["/categories", "/configuration"]);
});
