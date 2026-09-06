const {
  bindAdminTelegram,
  revokeExposedAdminTelegramBindingCode,
} = require("../services/laravelGateway");
const {t, isSupportedLang, normalizeLang} = require("../utils/i18n");

const CODE_PATTERN = /^[23456789A-HJ-NP-Z]{4}-?[23456789A-HJ-NP-Z]{4}$/i;

function commandCode(ctx) {
  return typeof ctx.match === "string" ? ctx.match.trim() : "";
}

function preferredLanguage(ctx) {
  if (isSupportedLang(ctx.session?.lang)) {
    return normalizeLang(ctx.session.lang);
  }

  const telegramLanguage = String(ctx.from?.language_code || "")
    .trim()
    .toLowerCase()
    .split("-")[0];

  return normalizeLang(telegramLanguage === "uk" ? "ua" : telegramLanguage);
}

function errorMessageKey(error) {
  const code = String(error?.code || "")
    .trim()
    .toUpperCase()
    .replace(/[.-]/g, "_");

  if (Number(error?.status) === 429 || code === "RATE_LIMIT_EXCEEDED") {
    return "telegram_bind_rate_limited";
  }

  if ([
    "TELEGRAM_BINDING_CODE_INVALID",
    "TELEGRAM_BINDING_CODE_INVALID_OR_EXPIRED",
    "BINDING_CODE_INVALID",
    "BINDING_CODE_EXPIRED",
    "VALIDATION_FAILED",
  ].includes(code)) {
    return "telegram_bind_invalid";
  }

  if ([
    "TELEGRAM_ACCOUNT_ALREADY_BOUND",
    "TELEGRAM_ACCOUNT_ALREADY_LINKED",
    "ADMIN_TELEGRAM_ALREADY_BOUND",
  ].includes(code)) {
    return "telegram_bind_already_bound";
  }

  if ([
    "TELEGRAM_BINDING_ADMIN_INACTIVE",
    "ADMIN_ACCOUNT_INACTIVE",
    "ADMIN_INACTIVE",
  ].includes(code)) {
    return "telegram_bind_admin_inactive";
  }

  return "telegram_bind_unavailable";
}

async function handleBind(ctx, code, dependencies = {}) {
  const lang = preferredLanguage(ctx);
  const reply = (key) => ctx.reply(t(lang, key));
  const chatId = ctx.chat?.id ?? ctx.message?.chat?.id;
  const normalizedCode = String(code || "").trim();

  if (
    ctx.chat?.type !== "private" ||
    ctx.from?.id == null ||
    String(chatId) !== String(ctx.from.id)
  ) {
    if (!CODE_PATTERN.test(normalizedCode)) {
      return reply("telegram_bind_private_only");
    }

    const revoke = dependencies.revokeExposedCode || revokeExposedAdminTelegramBindingCode;
    const logger = dependencies.logger || console;

    // Remove the public message immediately; revocation may wait on HTTP retries.
    let deleteMessage = Promise.resolve();
    try {
      deleteMessage = Promise.resolve(ctx.deleteMessage?.()).catch(() => undefined);
    } catch {
      // Best effort: group permissions may not allow the bot to delete the command.
    }
    let revoked = true;

    try {
      await Promise.all([revoke(ctx, normalizedCode), deleteMessage]);
    } catch (error) {
      revoked = false;
      logger.error("Could not revoke exposed Telegram admin binding code.", {
        code: String(error?.code || "UNKNOWN"),
        status: Number(error?.status) || 0,
        requestId: error?.requestId || null,
      });
      await deleteMessage;
    }

    return reply(
      revoked
        ? "telegram_bind_exposed_revocation_attempted"
        : "telegram_bind_exposed_revocation_failed"
    );
  }

  if (!CODE_PATTERN.test(normalizedCode)) {
    return reply("telegram_bind_usage");
  }

  const bind = dependencies.bindAdminTelegram || bindAdminTelegram;

  try {
    await bind(ctx, normalizedCode);
    return reply("telegram_bind_success");
  } catch (error) {
    const key = errorMessageKey(error);

    if (key === "telegram_bind_unavailable") {
      const logger = dependencies.logger || console;
      logger.error("Telegram admin binding failed.", {
        code: String(error?.code || "UNKNOWN"),
        status: Number(error?.status) || 0,
        requestId: error?.requestId || null,
      });
    }

    return reply(key);
  }
}

async function bindCommand(ctx) {
  return handleBind(ctx, commandCode(ctx));
}

module.exports = bindCommand;
module.exports.commandCode = commandCode;
module.exports.errorMessageKey = errorMessageKey;
module.exports.handleBind = handleBind;
module.exports.preferredLanguage = preferredLanguage;
