const {randomUUID} = require("crypto");
const {positiveInteger} = require("../config/runtime");

const RETRYABLE_STATUSES = new Set([429, 502, 503, 504]);

class BotApiError extends Error {
  constructor(code, message, {status = 0, fields = {}, details = {}, requestId = null} = {}) {
    super(message);
    this.name = "BotApiError";
    this.code = code;
    this.status = status;
    this.fields = fields;
    this.details = details;
    this.requestId = requestId;
  }
}

class BotApiClient {
  constructor({baseUrl, token, fetchImpl = globalThis.fetch, timeoutMs, maxAttempts} = {}) {
    this.baseUrl = String(baseUrl || process.env.BOT_API_URL || "").replace(/\/+$/, "");
    this.token = String(token || process.env.BOT_API_TOKEN || "");
    this.fetch = fetchImpl;
    this.timeoutMs = timeoutMs || positiveInteger("BOT_API_TIMEOUT_MS", 5000);
    this.maxAttempts = maxAttempts || positiveInteger("BOT_API_MAX_ATTEMPTS", 3);

    if (!this.baseUrl || !this.token || typeof this.fetch !== "function") {
      throw new Error("BOT_API_URL, BOT_API_TOKEN and fetch are required");
    }
  }

  async request(method, path, options = {}) {
    const {
      telegramId = null,
      idempotencyKey = null,
      body,
      formData,
      query,
      safeRetry = false,
    } = options;
    const url = this.buildUrl(path, query);
    const canRetry = method === "GET" || Boolean(idempotencyKey) || safeRetry;
    const attempts = canRetry ? this.maxAttempts : 1;
    const requestId = `bot-${randomUUID()}`;
    let lastError;

    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), this.timeoutMs);
      const headers = {
        Accept: "application/json",
        Authorization: `Bearer ${this.token}`,
        "X-Request-ID": requestId,
      };
      if (telegramId != null) headers["X-Telegram-User-ID"] = String(telegramId);
      if (idempotencyKey) headers["Idempotency-Key"] = String(idempotencyKey);
      if (!formData && body !== undefined) headers["Content-Type"] = "application/json";

      try {
        const response = await this.fetch(url, {
          method,
          headers,
          body: formData || (body === undefined ? undefined : JSON.stringify(body)),
          signal: controller.signal,
        });
        const payload = await this.readPayload(response);

        if (response.ok) return payload;

        const apiError = this.toApiError(response, payload);
        if (attempt < attempts && RETRYABLE_STATUSES.has(response.status)) {
          await this.delay(this.retryDelay(response, attempt));
          lastError = apiError;
          continue;
        }
        throw apiError;
      } catch (error) {
        if (error instanceof BotApiError) {
          if (attempt < attempts && RETRYABLE_STATUSES.has(error.status)) {
            lastError = error;
            await this.delay(this.retryDelay(null, attempt));
            continue;
          }
          throw error;
        }

        lastError = new BotApiError(
          "BOT_API_UNAVAILABLE",
          "Booking service is temporarily unavailable.",
          {details: {cause: error.name === "AbortError" ? "timeout" : "network"}}
        );
        if (attempt < attempts) {
          await this.delay(this.retryDelay(null, attempt));
          continue;
        }
      } finally {
        clearTimeout(timeout);
      }
    }

    throw lastError;
  }

  get(path, options = {}) {
    return this.request("GET", path, options);
  }

  post(path, options = {}) {
    return this.request("POST", path, options);
  }

  patch(path, options = {}) {
    return this.request("PATCH", path, options);
  }

  buildUrl(path, query) {
    const url = new URL(`${this.baseUrl}/${String(path).replace(/^\/+/, "")}`);
    Object.entries(query || {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== "") {
        url.searchParams.set(key, String(value));
      }
    });
    return url;
  }

  async readPayload(response) {
    const text = await response.text();
    if (!text) return {};
    try {
      return JSON.parse(text);
    } catch (error) {
      throw new BotApiError("BOT_API_INVALID_RESPONSE", "Booking service returned invalid data.", {
        status: response.status,
      });
    }
  }

  toApiError(response, payload) {
    const error = payload?.error || {};
    return new BotApiError(error.code || `HTTP_${response.status}`, error.message || "Request failed.", {
      status: response.status,
      fields: error.fields || {},
      details: error.details || {},
      requestId: error.request_id || null,
    });
  }

  retryDelay(response, attempt) {
    const retryAfter = Number(response?.headers?.get?.("retry-after"));
    if (Number.isFinite(retryAfter) && retryAfter > 0) {
      return Math.min(retryAfter * 1000, 5000);
    }
    return Math.min(150 * 2 ** (attempt - 1), 1200);
  }

  delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
  }
}

let singleton;

function getBotApiClient() {
  singleton ||= new BotApiClient();
  return singleton;
}

function idempotencyKey(ctx, operation) {
  const updateId = ctx.update?.update_id;
  if (!Number.isInteger(updateId)) throw new Error("Telegram update_id is required for idempotency");
  return `telegram:${ctx.from.id}:${updateId}:${operation}`;
}

module.exports = {
  BotApiClient,
  BotApiError,
  getBotApiClient,
  idempotencyKey,
};
