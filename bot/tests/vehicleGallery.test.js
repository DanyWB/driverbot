const {test} = require("node:test");
const assert = require("node:assert/strict");
const {BotScreenRenderer} = require("../services/botScreenRenderer");
const {normalizeVehicle} = require("../services/laravelGateway");

for (const count of [1, 3, 4]) {
  test(`gallery sends ${count} originals and cleans up only its own messages`, async () => {
    let id = 10;
    const sent = [], deleted = [], media = [];
    const ctx = {chat: {id: 7}, session: {}, api: {
      sendMediaGroup: async (_, items) => {media.push(...items.map(i => i.media)); return items.map(() => ({message_id: id++}));},
      sendPhoto: async (_, photo) => {media.push(photo); return {message_id: id++};},
      sendMessage: async (_, text) => {sent.push(text); return {message_id: id++};},
      editMessageText: async () => true,
      deleteMessage: async (_, message) => deleted.push(message),
    }};
    const renderer = new BotScreenRenderer();
    const photos = Array.from({length: count}, (_, i) => `https://example.test/${i}.jpg`);
    const description = "<Full description & details> ".repeat(200) + "THE END";
    await renderer.renderGallery(ctx, {screen: "vehicle", photos, description, text: "Book"});
    assert.deepEqual(media, photos);
    assert.equal(sent.at(-1), "Book");
    assert.ok(sent.slice(0, -1).every(text => text.length <= 3500));
    assert.ok(sent.slice(0, -1).join("").endsWith("THE END"));
    assert.ok(sent[0].startsWith("&lt;Full"));
    const attachments = [...ctx.session.uiAttachmentIds];
    await renderer.renderText(ctx, {screen: "menu", text: "Menu"});
    assert.deepEqual(deleted, attachments);
    assert.deepEqual(ctx.session.uiAttachmentIds, []);
  });
}

test("gateway chooses the original rather than the cropped thumbnail", () => {
  assert.equal(normalizeVehicle({primary_photo: {url: "original", thumbnail_url: "crop"}}).image_url, "original");
});

test("missing optimized photo never falls back to the cropped thumbnail", () => {
  assert.equal(normalizeVehicle({primary_photo: {url: null, original_url: "large", thumbnail_url: "crop"}}).image_url, null);
});

test("a rejected album falls back to individual photos and skips only the rejected photo", async () => {
  const attempted = [];
  const ctx = {chat: {id: 7}, session: {}, api: {
    sendMediaGroup: async () => {throw {error_code: 400};},
    sendPhoto: async (_, photo) => {attempted.push(photo); if (photo === "bad") throw {error_code: 400}; return {message_id: attempted.length};},
    sendMessage: async () => ({message_id: 10}),
  }};
  await new BotScreenRenderer({logger: {warn() {}}}).renderGallery(ctx, {screen: "vehicle", photos: ["first", "bad", "third", "fourth"], text: "Book"});
  assert.deepEqual(attempted, ["first", "bad", "third", "fourth"]);
  assert.deepEqual(ctx.session.uiAttachmentIds, [1, 3, 4]);
});

test("an uncertain album failure does not trigger duplicate sends", async () => {
  let attempts = 0;
  const ctx = {chat: {id: 7}, session: {}, api: {
    sendMediaGroup: async () => {throw new Error("timeout");},
    sendPhoto: async () => {attempts++;},
    sendMessage: async () => ({message_id: 10}),
  }};
  await new BotScreenRenderer({logger: {warn() {}}}).renderGallery(ctx, {screen: "vehicle", photos: ["first", "second"], text: "Book"});
  assert.equal(attempts, 0);
});
