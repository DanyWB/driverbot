const assert = require("node:assert/strict");
require("dotenv").config();
const {requireLaravelConfig} = require("../config/runtime");
const {BotApiClient} = require("../services/botApiClient");

async function main() {
  requireLaravelConfig();
  const client = new BotApiClient();
  const [configuration, categories] = await Promise.all([
    client.get("/configuration"),
    client.get("/categories"),
  ]);

  assert.equal(configuration.data.timezone, "Asia/Bangkok");
  assert.ok(configuration.data.terms_version);
  assert.ok(Array.isArray(categories.data));
  console.log("[ok] Laravel Bot API authentication and read endpoints");
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
