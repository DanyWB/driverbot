const assert = require("node:assert/strict");
require("dotenv").config();
const {requireExplicitLaravelMode} = require("../config/runtime");
const {BotApiClient} = require("../services/botApiClient");

async function main({env = process.env, Client = BotApiClient} = {}) {
  requireExplicitLaravelMode(env);
  const client = new Client();
  const [configuration, categories] = await Promise.all([
    client.get("/configuration"),
    client.get("/categories"),
  ]);

  assert.equal(configuration.data.timezone, "Asia/Bangkok");
  assert.ok(configuration.data.terms_version);
  assert.ok(Array.isArray(categories.data));
  console.log("[ok] Laravel Bot API authentication and read endpoints");
}

if (require.main === module) {
  main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
}

module.exports = {main};
