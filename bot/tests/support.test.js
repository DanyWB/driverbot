const assert = require("node:assert/strict");
const test = require("node:test");
const {supportKeyboard} = require("../handlers/support");

test("builds the manager support link from runtime configuration", () => {
  const keyboard = supportKeyboard("ru", "menu", "@drive_phangan");
  const adminButton = keyboard.inline_keyboard.flat().find((button) => button.url);

  assert.equal(adminButton.url, "https://t.me/drive_phangan");
});

test("omits the manager button when runtime configuration has no contact", () => {
  const keyboard = supportKeyboard("ru", "menu", null);

  assert.equal(keyboard.inline_keyboard.flat().some((button) => button.url), false);
});
