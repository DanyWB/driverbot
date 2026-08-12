const {t} = require("./i18n");

const CATEGORY_PRESENTATION = {
  "light-scooters": {
    labelKey: "category_light_label",
    fallbackIcon: "🛵",
    envKey: "TELEGRAM_CATEGORY_LIGHT_ICON_ID",
  },
  "comfort-scooters": {
    labelKey: "category_comfort_label",
    fallbackIcon: "🛵",
    envKey: "TELEGRAM_CATEGORY_COMFORT_ICON_ID",
  },
  "maxi-scooters": {
    labelKey: "category_maxi_label",
    fallbackIcon: "🛵",
    envKey: "TELEGRAM_CATEGORY_MAXI_ICON_ID",
  },
  cars: {
    labelKey: "category_cars_label",
    fallbackIcon: "🚗",
    envKey: "TELEGRAM_CATEGORY_CAR_ICON_ID",
  },
};
const warnedMissingCustomEmojiIds = new Set();

function fallbackPresentation(category) {
  const isCar = category?.vehicle_type === "car";
  return {
    labelKey: isCar ? "category_cars_label" : "category_scooters_label",
    fallbackIcon: isCar ? "🚗" : "🛵",
    envKey: null,
  };
}

function categoryPresentation(category, lang, options = {}) {
  const definition =
    CATEGORY_PRESENTATION[category?.code] || fallbackPresentation(category);
  const env = options.env || process.env;
  const customEmojiId = options.useCustomEmoji === false || !definition.envKey
    ? ""
    : String(env[definition.envKey] || "").trim();
  const label = t(lang, definition.labelKey);

  return {
    code: category?.code || null,
    label,
    fallbackIcon: definition.fallbackIcon,
    customEmojiId: customEmojiId || null,
  };
}

function categoryButton(category, lang, callbackData, options = {}) {
  const presentation = categoryPresentation(category, lang, options);
  const button = {
    text: presentation.customEmojiId
      ? presentation.label
      : `${presentation.fallbackIcon} ${presentation.label}`,
    callback_data: callbackData,
  };

  if (presentation.customEmojiId) {
    button.icon_custom_emoji_id = presentation.customEmojiId;
  }

  return button;
}

function categoryRows(categories, lang, callbackFor, options = {}) {
  return categories.map((category) => [
    categoryButton(category, lang, callbackFor(category), options),
  ]);
}

function isCustomEmojiRejection(error) {
  const code = Number(
    error?.error_code ||
    error?.response?.error_code ||
    error?.error?.error_code ||
    error?.status
  );
  return code === 400;
}

async function renderCategoryRowsWithFallback(
  ctx,
  categories,
  lang,
  callbackFor,
  render,
  options = {}
) {
  const env = options.env || process.env;
  const logger = options.logger || console;
  for (const category of categories) {
    const definition = CATEGORY_PRESENTATION[category?.code];
    const envKey = definition?.envKey;
    if (
      !envKey ||
      envKey === "TELEGRAM_CATEGORY_CAR_ICON_ID" ||
      String(env[envKey] || "").trim() ||
      warnedMissingCustomEmojiIds.has(envKey)
    ) {
      continue;
    }

    warnedMissingCustomEmojiIds.add(envKey);
    logger.warn?.(
      `[categories] ${envKey} is not configured; using the Unicode vehicle icon fallback.`
    );
  }

  const customEnabled = ctx.session?.categoryCustomEmojiFallback !== true;
  const rows = categoryRows(categories, lang, callbackFor, {
    useCustomEmoji: customEnabled,
    env,
  });
  const hasCustomEmoji = rows.flat().some((button) => button.icon_custom_emoji_id);

  try {
    return await render(rows);
  } catch (error) {
    if (!hasCustomEmoji || !isCustomEmojiRejection(error)) throw error;

    logger.warn?.(
      "Telegram rejected category custom emoji; using Unicode fallback:",
      error?.description || error?.message || "Bad Request"
    );
    if (ctx.session) ctx.session.categoryCustomEmojiFallback = true;
    return render(categoryRows(categories, lang, callbackFor, {
      useCustomEmoji: false,
      env,
    }));
  }
}

function clearCategoryPresentationWarnings() {
  warnedMissingCustomEmojiIds.clear();
}

module.exports = {
  CATEGORY_PRESENTATION,
  categoryButton,
  categoryPresentation,
  categoryRows,
  clearCategoryPresentationWarnings,
  renderCategoryRowsWithFallback,
};
