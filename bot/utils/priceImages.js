const fs = require("fs");
const path = require("path");

const SEASON_FILES = {
  high: ["high.png"],
  middle: ["middle.png", "miggle.png"],
  low: ["low.png"],
};

function priceImagesRoot() {
  return path.join(__dirname, "..", "images", "prices");
}

function priceImageCandidates(season, lang, root = priceImagesRoot()) {
  const filenames = SEASON_FILES[season] || [];
  const normalizedLang = String(lang || "").trim().toLowerCase();
  return [
    ...filenames.map((filename) => path.join(root, normalizedLang, filename)),
    ...filenames.map((filename) => path.join(root, filename)),
  ];
}

function resolvePriceImage(season, lang, options = {}) {
  const existsSync = options.existsSync || fs.existsSync;
  const candidates = priceImageCandidates(season, lang, options.root);
  return candidates.find((candidate) => existsSync(candidate)) || null;
}

module.exports = {
  SEASON_FILES,
  priceImageCandidates,
  priceImagesRoot,
  resolvePriceImage,
};
