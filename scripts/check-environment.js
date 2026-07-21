const net = require("net");
const db = require("../bot/connect");

const REDIS_HOST = process.env.REDIS_HOST || "127.0.0.1";
const REDIS_PORT = Number(process.env.REDIS_PORT || 6379);

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
  const result = await db.raw(`
    select
      version() as version,
      current_database() as database,
      current_setting('TimeZone') as timezone
  `);
  const info = result.rows[0];

  console.log(`[ok] PostgreSQL: ${info.version}`);
  console.log(`[ok] Database: ${info.database}, session timezone: ${info.timezone}`);

  await checkRedis();
  console.log(`[ok] Redis: ${REDIS_HOST}:${REDIS_PORT}`);
}

main()
  .catch((error) => {
    console.error(`[fail] Environment: ${error.message}`);
    process.exitCode = 1;
  })
  .finally(() => db.destroy());
