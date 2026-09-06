const DATA_MODES = new Set(["legacy", "laravel"]);

function dataMode(env = process.env) {
  const nodeEnvironment = String(env.NODE_ENV || "").trim().toLowerCase();
  const defaultMode = nodeEnvironment === "production" ? "laravel" : "legacy";
  const mode = String(env.BOT_DATA_MODE || defaultMode).trim().toLowerCase();
  if (!DATA_MODES.has(mode)) {
    throw new Error(`Unsupported BOT_DATA_MODE="${mode}"`);
  }
  return mode;
}

function isLaravelMode(env = process.env) {
  return dataMode(env) === "laravel";
}

function requireLaravelConfig(env = process.env) {
  if (!isLaravelMode(env)) return;

  const required = ["BOT_API_URL", "BOT_API_TOKEN", "REDIS_URL"];
  const missing = required.filter((key) => !String(env[key] || "").trim());
  if (missing.length) {
    throw new Error(`Missing Laravel bot configuration: ${missing.join(", ")}`);
  }
}

function requireExplicitLaravelMode(env = process.env) {
  const configuredMode = String(env.BOT_DATA_MODE || "").trim().toLowerCase();
  if (configuredMode !== "laravel") {
    throw new Error("BOT_DATA_MODE must be explicitly set to laravel for this check");
  }
  requireLaravelConfig(env);
}

function positiveInteger(name, fallback) {
  const value = Number(process.env[name] || fallback);
  return Number.isInteger(value) && value > 0 ? value : fallback;
}

module.exports = {
  dataMode,
  isLaravelMode,
  positiveInteger,
  requireExplicitLaravelMode,
  requireLaravelConfig,
};
