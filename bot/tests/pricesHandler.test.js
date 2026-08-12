const assert = require("node:assert/strict");
const test = require("node:test");
const {
  clearPriceFileIdCache,
  handlePricesActionWithDeps,
  sendPricesMenu,
  sendSeasonMenu,
} = require("../handlers/prices");

function callbackCtx(action, lang = "en") {
  return {
    callbackQuery: {data: action},
    from: {id: 1},
    session: {lang},
  };
}

test("prices menu is rendered in every runtime mode through one route", async () => {
  let payload;
  const renderer = {async renderText(_ctx, value) { payload = value; }};
  await sendPricesMenu({session: {lang: "ru"}}, "ru", {renderer});
  assert.equal(payload.screen, "prices_menu");
  assert.equal(payload.replyMarkup.inline_keyboard.length, 3);
  assert.deepEqual(
    payload.replyMarkup.inline_keyboard.slice(0, 2).flat().map((button) => button.callback_data),
    ["prices:type:bikes", "prices:type:cars"]
  );

  await sendSeasonMenu({session: {lang: "ru"}}, "cars", "ru", {renderer});
  assert.equal(payload.screen, "prices_cars_seasons");
  assert.deepEqual(
    payload.replyMarkup.inline_keyboard.slice(0, 3).flat().map((button) => button.callback_data),
    [
      "prices:season:high:cars",
      "prices:season:middle:cars",
      "prices:season:low:cars",
    ]
  );
});

test("all bike and car seasons render photos and cache Telegram file ids by path", async () => {
  clearPriceFileIdCache();
  const photos = [];
  let inputFiles = 0;
  const renderer = {
    async renderPhoto(_ctx, payload) {
      photos.push(payload);
      return {result: {photo: [{file_id: `cached-${payload.screen}`}]}};
    },
  };
  const deps = {
    renderer,
    resolveImage: (season, lang, options) =>
      `C:/prices/${lang}/${options.type}_${season}.png`,
    createInputFile: (filePath) => {
      inputFiles += 1;
      return {filePath};
    },
  };

  for (const type of ["bikes", "cars"]) {
    for (const season of ["high", "middle", "low"]) {
      await handlePricesActionWithDeps(
        callbackCtx(`prices:season:${season}:${type}`),
        deps
      );
    }
  }
  await handlePricesActionWithDeps(callbackCtx("prices:season:high:bikes"), deps);

  assert.equal(photos.length, 7);
  assert.equal(inputFiles, 6);
  assert.equal(photos[6].photo, "cached-prices_bikes_high");
});

test("missing or unreadable image logs and falls back to localized text", async () => {
  clearPriceFileIdCache();
  const textScreens = [];
  const log = [];
  const renderer = {
    async renderPhoto() { throw new Error("read failed"); },
    async renderText(_ctx, payload) { textScreens.push(payload); return payload; },
  };
  const logger = {
    warn(message, context) { log.push({level: "warn", message, context}); },
    error(message, context) { log.push({level: "error", message, context}); },
  };

  await handlePricesActionWithDeps(callbackCtx("prices:season:low", "ru"), {
    renderer,
    logger,
    resolveImage: () => null,
  });
  await handlePricesActionWithDeps(callbackCtx("prices:season:high", "en"), {
    renderer,
    logger,
    resolveImage: () => "C:/prices/high.png",
    createInputFile: (filePath) => ({filePath}),
  });

  assert.equal(textScreens.length, 2);
  assert.equal(textScreens.every((screen) => screen.text.includes("prices_image_missing") === false), true);
  assert.deepEqual(log.map((entry) => entry.level), ["warn", "error"]);
});
