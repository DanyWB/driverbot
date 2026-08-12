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

test("vehicle-specific price images use localized-first and universal fallback", () => {
  const root = path.join("tmp", "prices");
  const localizedCar = path.join(root, "ru", "cars_high.PNG");
  const universalBike = path.join(root, "bikes_middle.PNG");

  assert.equal(
    resolvePriceImage("high", "ru", {
      root,
      type: "cars",
      existsSync: (candidate) => candidate === localizedCar,
    }),
    localizedCar
  );
  assert.equal(
    resolvePriceImage("middle", "en", {
      root,
      type: "bikes",
      existsSync: (candidate) => candidate === universalBike,
    }),
    universalBike
  );
});

test("all supplied universal bike and car price images resolve", () => {
  for (const type of ["bikes", "cars"]) {
    for (const season of ["high", "middle", "low"]) {
      assert.match(
        resolvePriceImage(season, "en", {type}),
        new RegExp(`${type}_${season}\\.PNG$`)
      );
    }
  }
});
