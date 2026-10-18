const assert = require("node:assert/strict");
const test = require("node:test");
const {
  conditionsKeyboard,
  handleConditionsActionWithDeps,
  normalizeTermsOrigin,
  sendConditions,
} = require("../handlers/conditions");

const acceptedGateway = {
  async getProfile() { return {terms: {current: true, version: "v2"}}; },
  async getConfiguration() { return {terms_version: "v2"}; },
};

async function inLaravelMode(callback) {
  const original = process.env.BOT_DATA_MODE;
  process.env.BOT_DATA_MODE = "laravel";
  try {
    return await callback();
  } finally {
    if (original === undefined) delete process.env.BOT_DATA_MODE;
    else process.env.BOT_DATA_MODE = original;
  }
}

test("conditions screen records a supported origin and has accept/back controls", async () => {
  await inLaravelMode(async () => {
    let rendered;
    const ctx = {
      from: {id: 1},
      session: {lang: "en", acceptTerms: true, acceptedTermsVersion: "v2"},
    };
    await sendConditions(ctx, "en", {
      origin: "booking_confirmation",
      gateway: acceptedGateway,
      renderer: {async renderText(_ctx, payload) { rendered = payload; }},
    });

    assert.equal(ctx.session.termsOrigin, "booking_confirmation");
    assert.deepEqual(rendered.returnContext, {termsOrigin: "booking_confirmation"});
    assert.deepEqual(
      conditionsKeyboard("en", false).inline_keyboard.flat().map((button) => button.callback_data),
      ["conditions:accept", "conditions:contract", "conditions:back"]
    );
    assert.equal(normalizeTermsOrigin("untrusted"), "main_menu");
  });
});

test("accepting terms returns to the same booking draft without clearing it", async () => {
  await inLaravelMode(async () => {
    const draft = {startDate: "2026-09-01", selectedBikeId: 12};
    const cart = [{bike_id: 12}];
    const ctx = {
      callbackQuery: {data: "conditions:accept"},
      from: {id: 1},
      session: {
        lang: "ru",
        acceptTerms: true,
        acceptedTermsVersion: "v2",
        termsOrigin: "booking_confirmation",
        booking: draft,
        bookingCart: cart,
      },
    };
    const bookDraft = require("../handlers/book_draft");
    const original = bookDraft.showBookingDraft;
    let returned = 0;
    bookDraft.showBookingDraft = async (_ctx, options) => {
      returned += 1;
      assert.equal(options.navigationMode, "back");
    };

    try {
      await handleConditionsActionWithDeps(ctx, {
        gateway: acceptedGateway,
        renderer: {async renderText() {}},
      });
    } finally {
      bookDraft.showBookingDraft = original;
    }

    assert.equal(returned, 1);
    assert.equal(ctx.session.booking, draft);
    assert.equal(ctx.session.bookingCart, cart);
    assert.equal(ctx.session.termsOrigin, null);
  });
});

test("stale terms buttons display the current version without accepting unseen terms", async () => {
  await inLaravelMode(async () => {
    const ctx = {from: {id: 1}, callbackQuery: {data: "conditions:accept"},
      session: {lang: "en", viewedTermsVersion: "v1", termsOrigin: "booking_confirmation"}};
    let accepted = 0;
    let rendered;
    await handleConditionsActionWithDeps(ctx, {
      gateway: {
        async getProfile() { return {terms: {current: false, version: "v1"}}; },
        async getConfiguration() { return {terms_version: "v2"}; },
        async acceptTerms() { accepted += 1; },
      },
      renderer: {async renderText(_ctx, payload) { rendered = payload; }},
    });
    assert.equal(accepted, 0);
    assert.equal(ctx.session.viewedTermsVersion, "v2");
    assert.equal(rendered.screen, "conditions");
  });
});

test("back from main-menu conditions returns to main menu", async () => {
  const ctx = {
    callbackQuery: {data: "conditions:back"},
    session: {lang: "en", termsOrigin: "main_menu"},
  };
  const mainMenu = require("../handlers/main_menu");
  const original = mainMenu.showMainMenu;
  let returned = 0;
  mainMenu.showMainMenu = async () => { returned += 1; };
  try {
    await handleConditionsActionWithDeps(ctx, {
      renderer: {async renderText() {}},
    });
  } finally {
    mainMenu.showMainMenu = original;
  }
  assert.equal(returned, 1);
  assert.equal(ctx.session.termsOrigin, null);
});
