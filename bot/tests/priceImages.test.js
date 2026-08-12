const assert = require("node:assert/strict");
const path = require("path");
const test = require("node:test");
const {
  priceImageCandidates,
  resolvePriceImage,
} = require("../utils/priceImages");

test("localized price image has priority over a universal image", () => {
  const root = path.join("tmp", "prices");
  const candidates = priceImageCandidates("high", "ru", root);
  const existing = new Set(candidates);

  assert.equal(
    resolvePriceImage("high", "ru", {
      root,
      existsSync: (candidate) => existing.has(candidate),
    }),
    path.join(root, "ru", "high.png")
  );
});

test("universal price image is used when localized image is absent", () => {
  const root = path.join("tmp", "prices");
  const universal = path.join(root, "middle.png");

  assert.equal(
    resolvePriceImage("middle", "en", {
      root,
      existsSync: (candidate) => candidate === universal,
    }),
    universal
  );
});

test("missing price images produce a null fallback without throwing", () => {
  assert.equal(
    resolvePriceImage("low", "ru", {existsSync: () => false}),
    null
  );
  assert.equal(resolvePriceImage("unknown", "ru", {existsSync: () => true}), null);
});
