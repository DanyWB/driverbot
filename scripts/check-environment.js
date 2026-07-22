const net = require("net");
const path = require("path");
const {createRequire} = require("module");
const requireFromBot = createRequire(
  path.join(__dirname, "..", "bot", "package.json")
);
requireFromBot("dotenv").config({
  path: path.join(__dirname, "..", "bot", ".env"),
});
const {isLaravelMode} = require("../bot/config/runtime");

const redisUrl = new URL(process.env.REDIS_URL || "redis://127.0.0.1:6379");
const REDIS_HOST = process.env.REDIS_HOST || redisUrl.hostname || "127.0.0.1";
const REDIS_PORT = Number(process.env.REDIS_PORT || redisUrl.port || 6379);

function checkRedis() {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({host: REDIS_HOST, port: REDIS_PORT});
    let response = "";
    let settled = false;

    const finish = (error) => {
      if (settled) return;
      settled = true;
      socket.destroy();
      if (error) reject(error);
      else resolve();
    };

    socket.setTimeout(3000);
    socket.once("connect", () => socket.write("*1\r\n$4\r\nPING\r\n"));
    socket.on("data", (chunk) => {
      response += chunk.toString("utf8");
      if (response.includes("\r\n")) {
        const error = response.startsWith("+PONG")
          ? null
          : new Error(`Unexpected response: ${response.trim()}`);
        finish(error);
      }
    });
    socket.once("timeout", () => finish(new Error("connection timed out")));
    socket.once("error", finish);
  });
}

async function main() {
  if (isLaravelMode()) {
    const apiUrl = new URL(process.env.BOT_API_URL || "http://127.0.0.1:8000/api/v1/bot");
    const response = await fetch(`${apiUrl.origin}/health/ready`);
    const health = await response.json();
    if (!response.ok || health.status !== "ready") {
      throw new Error(`Laravel readiness failed with HTTP ${response.status}`);
    }
    console.log(`[ok] Laravel: ${apiUrl.origin}/health/ready`);
  } else {
    const db = require("../bot/connect");
    try {
      const result = await db.raw(`
        select
          version() as version,
          current_database() as database,
          current_setting('TimeZone') as timezone
      `);
      const info = result.rows[0];

      console.log(`[ok] PostgreSQL: ${info.version}`);
      console.log(`[ok] Database: ${info.database}, session timezone: ${info.timezone}`);
    } finally {
      await db.destroy();
    }
  }

  await checkRedis();
  console.log(`[ok] Redis: ${REDIS_HOST}:${REDIS_PORT}`);
}

main()
  .catch((error) => {
    console.error(`[fail] Environment: ${error.message}`);
    process.exitCode = 1;
  });
