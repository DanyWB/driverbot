const DATA_MODES = new Set(["legacy", "laravel"]);

function dataMode() {
  const mode = String(process.env.BOT_DATA_MODE || "legacy").trim().toLowerCase();
  if (!DATA_MODES.has(mode)) {
    throw new Error(`Unsupported BOT_DATA_MODE="${mode}"`);
  }
  return mode;
}

function isLaravelMode() {
  return dataMode() === "laravel";
}

function requireLaravelConfig() {
  if (!isLaravelMode()) return;

  const required = ["BOT_API_URL", "BOT_API_TOKEN", "REDIS_URL"];
  const missing = required.filter((key) => !String(process.env[key] || "").trim());
  if (missing.length) {
    throw new Error(`Missing Laravel bot configuration: ${missing.join(", ")}`);
  }
}

function positiveInteger(name, fallback) {
  const value = Number(process.env[name] || fallback);
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

module.exports = {
  dataMode,
  isLaravelMode,
  positiveInteger,
  requireLaravelConfig,
};
