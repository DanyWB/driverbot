const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const {handleLaravelRentMenuActionWithDeps} = require("../handlers/laravel_rent_menu");
const {BotScreenRenderer} = require("../services/botScreenRenderer");
const {t} = require("../utils/i18n");

for (const lang of ["ru", "en", "ua"]) {
  for (const action of ["rent:contract", "conditions:contract"]) {
    test(`${action} in ${lang} sends only the opaque first page with the existing filename and text`, async () => {
      let payload;
      await handleLaravelRentMenuActionWithDeps({
        callbackQuery: {data: action}, session: {lang},
      }, {renderer: {async renderDocuments(_ctx, screen) { payload = screen; }}});

      assert.equal(payload.screen, "rent_contract");
      assert.deepEqual(payload.documents, [{
        path: path.join(__dirname, "../assets/contracts/opaque", `agreement-${lang}.png`),
        filename: `Drive-Phangan-agreement-${lang}.png`,
      }]);
      assert.ok(fs.existsSync(payload.documents[0].path));
      assert.equal(payload.text, t(lang, "rent_contract_copy"));
      assert.equal(payload.parseMode, "HTML");
      assert.equal(payload.replyMarkup.inline_keyboard[0][0].callback_data,
        action === "conditions:contract" ? "conditions:open" : "rent:menu");
    });
  }
}

test("unsupported session language retains the existing Russian contract fallback", async () => {
  let payload;
  await handleLaravelRentMenuActionWithDeps({
    callbackQuery: {data: "rent:contract"}, session: {lang: "unknown"},
  }, {renderer: {async renderDocuments(_ctx, screen) { payload = screen; }}});
  assert.equal(payload.documents.length, 1);
  assert.match(payload.documents[0].path, /agreement-ru\.png$/);
});

test("real screen renderer uploads a document, never a compressed photo or a second page", async () => {
  const documents = [], messages = [];
  const ctx = {
    from: {id: 123}, session: {lang: "en"}, callbackQuery: {data: "rent:contract"},
    async answerCallbackQuery() {},
    api: {
      async sendDocument(chatId, document) {
        documents.push({chatId, document});
        return {message_id: 1};
      },
      async sendMessage(chatId, text) {
        messages.push({chatId, text});
        return {message_id: 2};
      },
      async sendPhoto() { assert.fail("contracts must not use Telegram photo compression"); },
      async sendMediaGroup() { assert.fail("only the first page is required"); },
    },
  };
  await handleLaravelRentMenuActionWithDeps(ctx, {renderer: new BotScreenRenderer()});
  assert.equal(documents.length, 1);
  assert.equal(documents[0].chatId, 123);
  assert.equal(messages.length, 1);
  assert.equal(messages[0].text, t("en", "rent_contract_copy"));
  assert.deepEqual(ctx.session.uiAttachmentIds, [1]);
});
