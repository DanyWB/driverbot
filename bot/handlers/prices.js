const {InputFile} = require("grammy");
const {t, getCtxLang} = require("../utils/i18n");
const {resolvePriceImage} = require("../utils/priceImages");
const {botScreenRenderer} = require("../services/botScreenRenderer");

const SEASON_LABELS = {
  low: "prices_season_low",
  middle: "prices_season_middle",
  high: "prices_season_high",
};

// Telegram file_id values are scoped to this bot. Keying by the resolved path
// keeps localized and universal assets independent and invalidates naturally
// when an operator changes the configured file layout.
const PRICE_FILE_IDS = new Map();

function getPricesMenuKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, SEASON_LABELS.high), callback_data: "prices:season:high"}],
      [{text: t(lang, SEASON_LABELS.middle), callback_data: "prices:season:middle"}],
      [{text: t(lang, SEASON_LABELS.low), callback_data: "prices:season:low"}],
      [{text: t(lang, "btn_main_menu"), callback_data: "menu:main"}],
    ],
  };
}

function pricesBackKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, "btn_back"), callback_data: "prices:menu"}],
      [{text: t(lang, "btn_main_menu"), callback_data: "menu:main"}],
    ],
  };
}

async function sendPricesMenu(ctx, langOverride, options = {}) {
  const lang = langOverride || getCtxLang(ctx);
  const renderer = options.renderer || botScreenRenderer;
  return renderer.renderText(ctx, {
    screen: "prices_menu",
    text: t(lang, "prices_choose_season"),
    replyMarkup: getPricesMenuKeyboard(lang),
    returnContext: {origin: options.origin || "main_menu"},
    navigationMode: options.navigationMode || "push",
  });
}

function largestPhotoFileId(result) {
  const photos = result?.photo;
  if (!Array.isArray(photos) || photos.length === 0) return null;
  return photos[photos.length - 1]?.file_id || null;
}

async function handlePricesActionWithDeps(ctx, dependencies = {}) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data || "";
  const renderer = dependencies.renderer || botScreenRenderer;
  const resolveImage = dependencies.resolveImage || resolvePriceImage;
  const createInputFile = dependencies.createInputFile || ((filePath) => new InputFile(filePath));
  const logger = dependencies.logger || console;

  if (action === "prices:back") {
    return require("./main_menu").showMainMenu(ctx, lang);
  }
  if (action === "prices:menu") {
    return sendPricesMenu(ctx, lang, {
      renderer,
      origin: ctx.session?.returnContext?.origin || "main_menu",
      navigationMode: "back",
    });
  }

  const match = action.match(/^prices:season:(low|middle|high)$/);
  if (!match) return undefined;

  const season = match[1];
  const filePath = resolveImage(season, lang);
  if (!filePath) {
    logger.warn?.("[prices] Price image is missing.", {season, lang});
    return renderer.renderText(ctx, {
      screen: `prices_${season}_missing`,
      text: t(lang, "prices_image_missing"),
      replyMarkup: pricesBackKeyboard(lang),
      returnContext: {origin: "prices_menu", season},
    });
  }

  const cachedFileId = PRICE_FILE_IDS.get(filePath);
  try {
    const rendered = await renderer.renderPhoto(ctx, {
      screen: `prices_${season}`,
      photo: cachedFileId || createInputFile(filePath),
      caption: t(lang, SEASON_LABELS[season]),
      replyMarkup: pricesBackKeyboard(lang),
      returnContext: {origin: "prices_menu", season, filePath},
    });
    if (!cachedFileId) {
      const fileId = largestPhotoFileId(rendered?.result);
      if (fileId) PRICE_FILE_IDS.set(filePath, fileId);
    }
    return rendered;
  } catch (error) {
    logger.error?.("[prices] Telegram could not render the price image.", {
      season,
      lang,
      filePath,
      error: error?.description || error?.message || String(error),
    });
    return renderer.renderText(ctx, {
      screen: `prices_${season}_missing`,
      text: t(lang, "prices_image_missing"),
      replyMarkup: pricesBackKeyboard(lang),
      returnContext: {origin: "prices_menu", season},
      navigationMode: "replace",
    });
  }
}

async function handlePricesAction(ctx) {
  return handlePricesActionWithDeps(ctx);
}

function clearPriceFileIdCache() {
  PRICE_FILE_IDS.clear();
}

module.exports = {
  PRICE_FILE_IDS,
  clearPriceFileIdCache,
  getPricesMenuKeyboard,
  handlePricesAction,
  handlePricesActionWithDeps,
  largestPhotoFileId,
  pricesBackKeyboard,
  sendPricesMenu,
};
