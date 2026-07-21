const crypto = require("crypto");
const fs = require("fs");
const path = require("path");
const db = require("../bot/connect");

const ROLE_NAME = "drive_phangan_app";
const DATABASE_NAME = "drive_phangan";
const backendDir = path.resolve(__dirname, "..", "backend");
const envExamplePath = path.join(backendDir, ".env.example");
const envPath = path.join(backendDir, ".env");

function setEnvValue(contents, key, value) {
  const line = `${key}=${value}`;
  const pattern = new RegExp(`^${key}=.*$`, "m");

  return pattern.test(contents) ? contents.replace(pattern, line) : `${contents.trimEnd()}\n${line}\n`;
}

async function ensureDatabase(password) {
  const roleResult = await db.raw(
    "select exists(select 1 from pg_roles where rolname = ?) as exists",
    [ROLE_NAME],
  );

  const passwordSql = password.replaceAll("'", "''");
  if (roleResult.rows[0].exists) {
    await db.raw(`alter role ${ROLE_NAME} with login password '${passwordSql}'`);
  } else {
    await db.raw(`create role ${ROLE_NAME} with login password '${passwordSql}'`);
  }

  const databaseResult = await db.raw(
    "select exists(select 1 from pg_database where datname = ?) as exists",
    [DATABASE_NAME],
  );

  if (databaseResult.rows[0].exists) {
    await db.raw(`alter database ${DATABASE_NAME} owner to ${ROLE_NAME}`);
  } else {
    await db.raw(`create database ${DATABASE_NAME} owner ${ROLE_NAME}`);
  }
}

function writeLaravelEnv(password) {
  let contents = fs.readFileSync(envExamplePath, "utf8");
  const replacements = {
    APP_NAME: '"Drive Phangan"',
    APP_URL: "http://127.0.0.1:8000",
    APP_TIMEZONE: "UTC",
    BUSINESS_TIMEZONE: "Asia/Bangkok",
    DB_CONNECTION: "pgsql",
    DB_HOST: process.env.PG_HOST || "127.0.0.1",
    DB_PORT: process.env.PG_PORT || "5432",
    DB_DATABASE: DATABASE_NAME,
    DB_USERNAME: ROLE_NAME,
    DB_PASSWORD: password,
    SESSION_DRIVER: "redis",
    QUEUE_CONNECTION: "redis",
    CACHE_STORE: "redis",
    REDIS_CLIENT: "phpredis",
    REDIS_HOST: process.env.REDIS_HOST || "127.0.0.1",
    REDIS_PORT: process.env.REDIS_PORT || "6379",
  };

  for (const [key, value] of Object.entries(replacements)) {
    contents = setEnvValue(contents, key, value);
  }

  fs.writeFileSync(envPath, contents, {encoding: "utf8", mode: 0o600});
}

async function main() {
  if (!fs.existsSync(envExamplePath)) {
    throw new Error(`Laravel env template not found: ${envExamplePath}`);
  }

  const password = crypto.randomBytes(32).toString("base64url");
  await ensureDatabase(password);
  writeLaravelEnv(password);

  console.log(`[ok] PostgreSQL role: ${ROLE_NAME}`);
  console.log(`[ok] PostgreSQL database: ${DATABASE_NAME}`);
  console.log("[ok] Laravel .env written without exposing credentials");
}

main()
  .catch((error) => {
    console.error(`[fail] Laravel local setup: ${error.message}`);
    process.exitCode = 1;
  })
  .finally(() => db.destroy());
