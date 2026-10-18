const {test} = require("node:test");
const assert = require("node:assert/strict");
const {showBookingValidationError} = require("../services/bookingErrorScreen");

test("expired draft dates offer recovery without deleting cart or exposing API text", async () => {
  const messages = [];
  const cart = [{id: "old"}, {id: "new"}];
  const ctx = {chat: {id: 1}, session: {lang: "ru", bookingCart: cart}, api: {
    sendMessage: async (_, text, options) => {messages.push({text, options}); return {message_id: 1};},
  }};
  assert.equal(await showBookingValidationError(ctx, {code: "VALIDATION_FAILED", fields: {starts_on: ["Internal validation details"]}}), true);
  assert.match(messages[0].text, /Даты аренды/);
  assert.doesNotMatch(messages[0].text, /Internal/);
  assert.equal(ctx.session.bookingCart, cart);
  assert.equal(messages[0].options.reply_markup.inline_keyboard[1][0].callback_data, "book:reset_rental");
});

test("minimum error displays server minimum and returns to date selection", async () => {
  let output;
  const ctx = {chat: {id: 1}, session: {lang: "en"}, api: {
    sendMessage: async (_, text, options) => {output = {text, options}; return {message_id: 1};},
  }};
  await showBookingValidationError(ctx, {code: "MINIMUM_RENTAL_DAYS", details: {minimum_days: 3}}, "book:calendar_back_end");
  assert.match(output.text, /3 days/);
  assert.equal(output.options.reply_markup.inline_keyboard[0][0].callback_data, "book:calendar_back_end");
  assert.equal(await showBookingValidationError(ctx, {code: "OTHER_ERROR"}), false);
});
