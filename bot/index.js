const bot = require("./bot");
const {isLaravelMode} = require("./config/runtime");
const {closeRedisSession} = require("./middlewares/redisSessionStorage");

let shuttingDown = false;

async function shutdown(signal, exitCode = 0) {
  if (shuttingDown) return;
  shuttingDown = true;

  console.log(`Stopping Telegram bot (${signal})...`);

  try {
    await bot.stop();
  } catch (error) {
    console.error("Could not stop Telegram polling cleanly:", error);
    exitCode = 1;
  }

  if (isLaravelMode()) {
    try {
      await closeRedisSession();
    } catch (error) {
      console.error("Could not close Redis session client cleanly:", error);
      exitCode = 1;
    }
  }

  process.exit(exitCode);
}

process.once("SIGINT", () => void shutdown("SIGINT"));
process.once("SIGTERM", () => void shutdown("SIGTERM"));

bot.start().catch(async (error) => {
  console.error("Telegram bot startup failed:", error);
  await shutdown("startup failure", 1);
});
