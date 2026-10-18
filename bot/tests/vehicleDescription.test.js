const {test} = require("node:test");
const assert = require("node:assert/strict");
const {vehicleDescription} = require("../utils/vehicleDescription");

test("vehicle descriptions use all three customer languages", () => {
  const vehicle = {description: "Legacy", descriptions: {en: "English", ru: "Русский", ua: "Українська"}};
  assert.equal(vehicleDescription(vehicle, "en"), "English");
  assert.equal(vehicleDescription(vehicle, "ru"), "Русский");
  assert.equal(vehicleDescription(vehicle, "ua"), "Українська");
  assert.equal(vehicleDescription(vehicle, "uk"), "Українська");
});

test("missing translations fall back to English and preserve legacy descriptions", () => {
  assert.equal(vehicleDescription({descriptions: {ru: "  ", en: "English"}}, "ru"), "English");
  assert.equal(vehicleDescription({description: "Existing text"}, "ua"), "Existing text");
  assert.equal(vehicleDescription({descriptions: {en: "English"}}, "unknown"), "English");
  assert.equal(vehicleDescription({}, "ru"), "");
});
