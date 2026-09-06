const assert = require("node:assert/strict");
const test = require("node:test");

const {inspectFile, isProhibitedDumpFile} = require("./check-secrets");
const {historicalDumpPathsFromNameList} = require("./check-sensitive-history");

test("rejects legacy and generic database export paths", () => {
  assert.equal(isProhibitedDumpFile("bot/bd.sql"), true);
  assert.equal(isProhibitedDumpFile("driverbot.sql"), true);
  assert.equal(isProhibitedDumpFile("private/customer-data.dump"), true);
  assert.equal(isProhibitedDumpFile("private/customer-data.sql.gz"), true);
});

test("rejects customer rows even when the SQL filename looks harmless", () => {
  const failures = inspectFile(
    "fixtures/bootstrap.sql",
    "COPY public.users (id, phone) FROM stdin;\n",
  );

  assert.match(failures.join("\n"), /customer or booking rows/);
});

test("allows schema-only and catalog maintenance SQL", () => {
  assert.deepEqual(inspectFile("schema.sql", "CREATE TABLE users (id bigint);"), []);
  assert.deepEqual(inspectFile("bot/update_bikes_prices.sql", "INSERT INTO bike_prices VALUES (1, 2);"), []);
});

test("rejects real environment files and recognized token shapes", () => {
  assert.match(inspectFile(".env", "").join("\n"), /environment file/);

  const token = "123456789:" + "A".repeat(35);
  assert.match(inspectFile("notes.txt", token).join("\n"), /Telegram bot token/);
});

test("finds database exports in a Git history name list without exposing contents", () => {
  const nameList = [
    "safe/file.js",
    "bot/bd.sql",
    "historical/insert.sql",
    "bot/update_bikes_prices.sql",
  ].join("\n");

  assert.deepEqual(historicalDumpPathsFromNameList(nameList), [
    "bot/bd.sql",
    "historical/insert.sql",
  ]);
});
