const assert = require("node:assert/strict");
const test = require("node:test");
const {preview} = require("../utils/text");
const {vehicleEmoji} = require("../utils/vehicle");

test("shortens Telegram previews without splitting Unicode characters", () => {
  assert.equal(preview("Honda Click", 20), "Honda Click");
  assert.equal(preview("🚗🚗🚗🚗🚗", 4), "🚗...");
});

test("selects a vehicle icon by configured emoji and vehicle type", () => {
  assert.equal(vehicleEmoji({type: "car"}), "🚗");
  assert.equal(vehicleEmoji({type: "scooter"}), "🛵");
  assert.equal(vehicleEmoji({type: "car", emoji: "🏎️"}), "🏎️");
});
