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
  mainMenu.showMainMenu = async () => { shown += 1; };
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
