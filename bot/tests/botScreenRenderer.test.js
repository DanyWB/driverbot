const assert = require("node:assert/strict");
const test = require("node:test");
const {BotScreenRenderer} = require("../services/botScreenRenderer");

function fakeLogger() {
  const warnings = [];
  const errors = [];
  return {
    warnings,
    errors,
    warn(message, context) {
      warnings.push({message, context});
    },
    error(message, context) {
      errors.push({message, context});
    },
  };
}

function fakeContext({session = {}, callbackMessageId = 900} = {}) {
  const calls = {
    answer: [],
    delete: [],
    editText: [],
    sendMessage: [],
    sendPhoto: [],
  };
  let nextMessageId = 1000;

  const ctx = {
    chat: {id: 77},
    from: {id: 77},
    session,
    callbackQuery: {
      id: "callback-1",
      message: {message_id: callbackMessageId, chat: {id: 77}},
    },
    async answerCallbackQuery() {
      calls.answer.push("callback-1");
    },
    api: {
      async editMessageText(chatId, messageId, text, options) {
        calls.editText.push({chatId, messageId, text, options});
        return true;
      },
      async deleteMessage(chatId, messageId) {
        calls.delete.push({chatId, messageId});
        return true;
      },
      async sendMessage(chatId, text, options) {
        calls.sendMessage.push({chatId, text, options});
        return {message_id: nextMessageId++};
      },
      async sendPhoto(chatId, photo, options) {
        calls.sendPhoto.push({chatId, photo, options});
        return {message_id: nextMessageId++};
      },
    },
  };

  return {ctx, calls};
}

test("answers a callback and edits only the stored active text UI message", async () => {
  const logger = fakeLogger();
  const {ctx, calls} = fakeContext({
    callbackMessageId: 900,
    session: {
      activeUiMessageId: 321,
      activeUiMessageType: "text",
      currentScreen: "main_menu",
      returnContext: {source: "start"},
    },
  });
  const renderer = new BotScreenRenderer({logger});

  const result = await renderer.renderText(ctx, {
    screen: "history",
    text: "History",
    parseMode: "HTML",
    replyMarkup: {inline_keyboard: []},
    returnContext: {page: 2},
  });

  assert.equal(result.mode, "edited");
  assert.deepEqual(calls.answer, ["callback-1"]);
  assert.deepEqual(calls.editText, [
    {
      chatId: 77,
      messageId: 321,
      text: "History",
      options: {parse_mode: "HTML", reply_markup: {inline_keyboard: []}},
    },
  ]);
  assert.deepEqual(calls.delete, []);
  assert.deepEqual(calls.sendMessage, []);
  assert.equal(ctx.session.activeUiMessageId, 321);
  assert.equal(ctx.session.currentScreen, "history");
  assert.deepEqual(ctx.session.returnContext, {page: 2});
  assert.deepEqual(ctx.session.navigationStack, [
    {screen: "main_menu", returnContext: {source: "start"}},
  ]);
  assert.deepEqual(logger.warnings, []);
});

test("replaces text with photo without deleting a different transactional callback message", async () => {
  const {ctx, calls} = fakeContext({
    callbackMessageId: 900,
    session: {
      activeUiMessageId: 321,
      activeUiMessageType: "text",
      currentScreen: "prices_menu",
    },
  });
  const renderer = new BotScreenRenderer({logger: fakeLogger()});

  const result = await renderer.renderPhoto(ctx, {
    screen: "prices_high",
    photo: "telegram-file-id",
    caption: "High season",
  });

  assert.equal(result.mode, "replaced");
  assert.deepEqual(calls.delete, [{chatId: 77, messageId: 321}]);
  assert.equal(calls.delete.some((call) => call.messageId === 900), false);
  assert.equal(calls.sendPhoto.length, 1);
  assert.equal(calls.sendPhoto[0].options.caption, "High season");
  assert.equal(ctx.session.activeUiMessageId, 1000);
  assert.equal(ctx.session.activeUiMessageType, "photo");

  await renderer.renderText(ctx, {
    screen: "prices_menu",
    text: "Choose season",
    navigationMode: "back",
  });

  assert.deepEqual(calls.delete.at(-1), {chatId: 77, messageId: 1000});
  assert.equal(calls.sendMessage.length, 1);
  assert.equal(ctx.session.activeUiMessageId, 1001);
  assert.equal(ctx.session.activeUiMessageType, "text");
});

test("falls back to exactly one send after a text edit failure", async () => {
  const logger = fakeLogger();
  const {ctx, calls} = fakeContext({
    session: {
      activeUiMessageId: 321,
      activeUiMessageType: "text",
      currentScreen: "main_menu",
    },
  });
  ctx.api.editMessageText = async (...args) => {
    calls.editText.push(args);
    throw new Error("message to edit not found");
  };
  const renderer = new BotScreenRenderer({logger});

  const result = await renderer.renderText(ctx, {
    screen: "profile",
    text: "Profile",
  });

  assert.equal(result.mode, "replaced");
  assert.equal(calls.editText.length, 1);
  assert.equal(calls.delete.length, 1);
  assert.equal(calls.sendMessage.length, 1);
  assert.equal(ctx.session.activeUiMessageId, 1000);
  assert.equal(logger.warnings.length, 1);
});

test("continues with one send when best-effort callback answer and delete fail", async () => {
  const logger = fakeLogger();
  const {ctx, calls} = fakeContext({
    session: {
      activeUiMessageId: 321,
      activeUiMessageType: "photo",
      currentScreen: "prices_high",
    },
  });
  ctx.answerCallbackQuery = async () => {
    calls.answer.push("failed");
    throw new Error("query is too old");
  };
  ctx.api.deleteMessage = async (chatId, messageId) => {
    calls.delete.push({chatId, messageId});
    throw new Error("message can't be deleted");
  };
  const renderer = new BotScreenRenderer({logger});

  const result = await renderer.renderText(ctx, {
    screen: "main_menu",
    text: "Menu",
  });

  assert.equal(result.mode, "replaced");
  assert.equal(calls.sendMessage.length, 1);
  assert.equal(ctx.session.activeUiMessageId, 1000);
  assert.equal(logger.warnings.length, 2);
});

test("treats Telegram message-not-modified as a successful text render", async () => {
  const logger = fakeLogger();
  const {ctx, calls} = fakeContext({
    session: {
      activeUiMessageId: 321,
      activeUiMessageType: "text",
      currentScreen: "history",
      returnContext: {page: 1},
    },
  });
  ctx.api.editMessageText = async (...args) => {
    calls.editText.push(args);
    const error = new Error("Bad Request");
    error.description = "Bad Request: message is not modified";
    throw error;
  };
  const renderer = new BotScreenRenderer({logger});

  const result = await renderer.renderText(ctx, {
    screen: "history",
    text: "Same history",
    returnContext: {page: 2},
  });

  assert.equal(result.mode, "unchanged");
  assert.equal(calls.delete.length, 0);
  assert.equal(calls.sendMessage.length, 0);
  assert.equal(ctx.session.activeUiMessageId, 321);
  assert.deepEqual(ctx.session.returnContext, {page: 2});
  assert.deepEqual(logger.warnings, []);
});

test("does not overwrite active UI or navigation state when replacement send fails", async () => {
  const logger = fakeLogger();
  const originalSession = {
    activeUiMessageId: 321,
    activeUiMessageType: "text",
    currentScreen: "main_menu",
    returnContext: {source: "start"},
    navigationStack: [{screen: "welcome", returnContext: null}],
  };
  const {ctx, calls} = fakeContext({
    session: structuredClone(originalSession),
  });
  ctx.api.sendPhoto = async (chatId, photo, options) => {
    calls.sendPhoto.push({chatId, photo, options});
    throw new Error("Telegram unavailable");
  };
  const renderer = new BotScreenRenderer({logger});

  await assert.rejects(
    renderer.renderPhoto(ctx, {
      screen: "prices_high",
      photo: "photo",
    }),
    /Telegram unavailable/
  );

  assert.equal(calls.delete.length, 1);
  assert.equal(calls.sendPhoto.length, 1);
  assert.deepEqual(ctx.session, originalSession);
  assert.equal(logger.errors.length, 1);
});

test("sends a new UI message without touching a transactional callback when none is active", async () => {
  const {ctx, calls} = fakeContext({session: {}, callbackMessageId: 444});
  const renderer = new BotScreenRenderer({logger: fakeLogger(), stackLimit: 2});

  const result = await renderer.renderText(ctx, {
    screen: "main_menu",
    text: "Menu",
    navigationMode: "reset",
  });

  assert.equal(result.mode, "sent");
  assert.deepEqual(calls.delete, []);
  assert.equal(calls.sendMessage.length, 1);
  assert.equal(ctx.session.activeUiMessageId, 1000);
  assert.equal(ctx.session.currentScreen, "main_menu");
});
