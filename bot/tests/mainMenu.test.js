const assert = require("node:assert/strict");
const test = require("node:test");
const {
  detectMainMenuAction,
  getInlineMainMenuKeyboard,
  getMainMenuKeyboard,
} = require("../utils/mainMenu");
const {
  handleQuickAction,
  showMainMenu,
} = require("../handlers/main_menu");

test("reply keyboard contains exactly booking and prices in RU/EN", () => {
  for (const lang of ["ru", "en"]) {
    const buttons = getMainMenuKeyboard(lang).keyboard.flat();
    assert.equal(buttons.length, 2);
    assert.equal(detectMainMenuAction(buttons[0].text), "book");
    assert.equal(detectMainMenuAction(buttons[1].text), "prices");
  }
});

test("inline main menu exposes seven stable localized actions", () => {
  for (const lang of ["ru", "en"]) {
    const buttons = getInlineMainMenuKeyboard(lang).inline_keyboard.flat();
    assert.equal(buttons.length, 7);
    assert.deepEqual(buttons.map((button) => button.callback_data), [
      "menu:book",
      "menu:bookings",
      "menu:prices",
      "menu:conditions",
      "menu:profile",
      "menu:support",
      "menu:about",
    ]);
    assert.equal(buttons.some((button) => /^main_menu_|^menu_/.test(button.text)), false);
  }
});

test("showing main menu preserves an unfinished booking draft", async () => {
  let rendered;
  const booking = {startDate: "2026-08-15", selectedBikeId: 9};
  const ctx = {session: {lang: "en", booking, step: "input", scenario: "book"}};
  const renderer = {async renderText(_ctx, payload) { rendered = payload; }};

  await showMainMenu(ctx, "en", {renderer});

  assert.equal(ctx.session.booking, booking);
  assert.equal(ctx.session.step, null);
  assert.equal(ctx.session.scenario, null);
  assert.equal(rendered.screen, "main_menu");
  assert.equal(rendered.navigationMode, "reset");
});

test("the /menu command opens the menu screen without invoking /start", async () => {
  const mainMenu = require("../handlers/main_menu");
  const originalShow = mainMenu.showMainMenu;
  const startPath = require.resolve("../commands/start");
  const originalStartEntry = require.cache[startPath];
  let shown = 0;
  let showArgs;
  mainMenu.showMainMenu = async (...args) => {
    shown += 1;
    showArgs = args;
  };
  require.cache[startPath] = {
    id: startPath,
    filename: startPath,
    loaded: true,
    exports: async () => { throw new Error("/start must not be invoked"); },
    children: [],
    paths: module.paths,
  };

  try {
    await require("../commands/menu")({
      session: {booking: {startDate: "2026-08-15"}},
    });
  } finally {
    mainMenu.showMainMenu = originalShow;
    if (originalStartEntry) require.cache[startPath] = originalStartEntry;
    else delete require.cache[startPath];
  }
  assert.equal(shown, 1);
  assert.deepEqual(showArgs.slice(1), [
    undefined,
    {navigationMode: "reset", forceNewMessage: true},
  ]);
});

test("regular /start requests a fresh visible main-menu message", async () => {
  const userService = require("../services/userService");
  const commandService = require("../utils/setCommands");
  const mainMenu = require("../handlers/main_menu");
  const startPath = require.resolve("../commands/start");
  const originalStartEntry = require.cache[startPath];
  const originals = {
    getUserByTelegramId: userService.getUserByTelegramId,
    registerUser: userService.registerUser,
    setUserCommands: commandService.setUserCommands,
    showMainMenu: mainMenu.showMainMenu,
  };
  const profile = {
    telegram_id: 77,
    name: "Test User",
    phone: "+66000000000",
    lang: "ru",
    passport_photo_file_id: "stored",
    meta: {},
  };
  const replies = [];
  let menuOptions;

  userService.getUserByTelegramId = async () => profile;
  userService.registerUser = async () => profile;
  commandService.setUserCommands = async () => {};
  mainMenu.showMainMenu = async (_ctx, _lang, options) => {
    menuOptions = options;
  };
  delete require.cache[startPath];

  try {
    const start = require("../commands/start");
    await start({
      from: {id: 77, first_name: "Test"},
      message: {text: "/start"},
      session: {
        activeUiMessageId: 321,
        activeUiMessageType: "text",
        currentScreen: "prices_menu",
      },
      async reply(text, options) {
        replies.push({text, options});
        return {message_id: 900};
      },
    });
  } finally {
    userService.getUserByTelegramId = originals.getUserByTelegramId;
    userService.registerUser = originals.registerUser;
    commandService.setUserCommands = originals.setUserCommands;
    mainMenu.showMainMenu = originals.showMainMenu;
    if (originalStartEntry) require.cache[startPath] = originalStartEntry;
    else delete require.cache[startPath];
  }

  assert.equal(replies.length, 1);
  assert.ok(replies[0].options.reply_markup.keyboard);
  assert.deepEqual(menuOptions, {
    navigationMode: "reset",
    forceNewMessage: true,
  });
});

test("quick actions delete only the incoming command and reuse route handlers", async () => {
  const deleted = [];
  const ctx = {
    chat: {id: 44},
    message: {message_id: 55, text: "Prices", chat: {id: 44}},
    session: {lang: "en", booking: {keep: true}},
    api: {async deleteMessage(...args) { deleted.push(args); }},
  };
  const prices = require("../handlers/prices");
  const originalPrices = prices.sendPricesMenu;
  let pricesCalled = 0;
  prices.sendPricesMenu = async () => { pricesCalled += 1; };

  const bookPath = require.resolve("../commands/book");
  const originalBookEntry = require.cache[bookPath];
  let bookCalled = 0;
  require.cache[bookPath] = {
    id: bookPath,
    filename: bookPath,
    loaded: true,
    exports: async () => { bookCalled += 1; },
    children: [],
    paths: module.paths,
  };

  try {
    await handleQuickAction(ctx, "prices");
    await handleQuickAction(ctx, "book");
  } finally {
    prices.sendPricesMenu = originalPrices;
    if (originalBookEntry) require.cache[bookPath] = originalBookEntry;
    else delete require.cache[bookPath];
  }

  assert.deepEqual(deleted, [[44, 55], [44, 55]]);
  assert.equal(pricesCalled, 1);
  assert.equal(bookCalled, 1);
  assert.deepEqual(ctx.session.booking, {keep: true});
});
