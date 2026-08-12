const fs = require("fs");
const path = require("path");

const SEASON_FILES = {
  high: ["high.png"],
  middle: ["middle.png", "miggle.png"],
  low: ["low.png"],
};
const PRICE_IMAGE_TYPES = ["bikes", "cars"];

function priceImagesRoot() {
  return path.join(__dirname, "..", "images", "prices");
}

function priceImageFilenames(season, type = null) {
  if (!SEASON_FILES[season]) return [];
  if (!PRICE_IMAGE_TYPES.includes(type)) return SEASON_FILES[season];

  return [`${type}_${season}.PNG`, `${type}_${season}.png`];
}

function priceImageCandidates(season, lang, root = priceImagesRoot(), type = null) {
  const filenames = priceImageFilenames(season, type);
  const normalizedLang = String(lang || "").trim().toLowerCase();
  return [
    ...filenames.map((filename) => path.join(root, normalizedLang, filename)),
    ...filenames.map((filename) => path.join(root, filename)),
  ];
}

function resolvePriceImage(season, lang, options = {}) {
  const existsSync = options.existsSync || fs.existsSync;
  const candidates = priceImageCandidates(
    season,
    lang,
    options.root,
    options.type || null
  );
  return candidates.find((candidate) => existsSync(candidate)) || null;
}

module.exports = {
  PRICE_IMAGE_TYPES,
  SEASON_FILES,
  priceImageCandidates,
  priceImageFilenames,
  priceImagesRoot,
  resolvePriceImage,
};
