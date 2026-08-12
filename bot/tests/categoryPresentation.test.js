const assert = require("node:assert/strict");
const test = require("node:test");
const {
  categoryButton,
  categoryPresentation,
  clearCategoryPresentationWarnings,
  renderCategoryRowsWithFallback,
} = require("../utils/categoryPresentation");

const categories = {
  light: {id: 71, code: "light-scooters", name: "DB name", vehicle_type: "scooter"},
  comfort: {id: 3, code: "comfort-scooters", name: "Changed", vehicle_type: "scooter"},
  maxi: {id: 500, code: "maxi-scooters", name: "Technical", vehicle_type: "scooter"},
  cars: {id: 1, code: "cars", name: "Vehicles", vehicle_type: "car"},
};

test("category labels are localized from stable codes rather than IDs or names", () => {
  assert.equal(categoryPresentation(categories.light, "ru").label, "Легкие (100cc-110cc)");
  assert.equal(categoryPresentation(categories.comfort, "en").label, "Comfort (150cc-160cc)");
  assert.equal(categoryPresentation(categories.maxi, "ru").label, "Макс (300cc-350cc)");
  assert.equal(categoryPresentation(categories.cars, "en").label, "Cars");
});

test("category button uses a single custom icon when configured", () => {
  const button = categoryButton(categories.light, "en", "book:cat:71", {
    env: {TELEGRAM_CATEGORY_LIGHT_ICON_ID: "custom-green-bike"},
  });

  assert.deepEqual(button, {
    text: "Light (100cc-110cc)",
    callback_data: "book:cat:71",
    icon_custom_emoji_id: "custom-green-bike",
  });
});

test("missing custom icon falls back to a normal Unicode vehicle icon", () => {
  const button = categoryButton(categories.maxi, "ru", "book:cat:500", {env: {}});

  assert.equal(button.text, "🛵 Макс (300cc-350cc)");
  assert.equal(button.icon_custom_emoji_id, undefined);
});

test("missing scooter custom icon logs one diagnostic warning", async () => {
  clearCategoryPresentationWarnings();
  const warnings = [];
  const render = async (rows) => rows;
  const options = {
    env: {},
    logger: {warn: (...args) => warnings.push(args)},
  };

  await renderCategoryRowsWithFallback(
    {session: {}},
    [categories.light, categories.cars],
    "en",
    (category) => `book:cat:${category.id}`,
    render,
    options
  );
  await renderCategoryRowsWithFallback(
    {session: {}},
    [categories.light],
    "en",
    (category) => `book:cat:${category.id}`,
    render,
    options
  );

  assert.equal(warnings.length, 1);
  assert.match(warnings[0][0], /TELEGRAM_CATEGORY_LIGHT_ICON_ID/);
});

test("Telegram custom icon rejection retries once with Unicode fallback", async () => {
  const previous = process.env.TELEGRAM_CATEGORY_LIGHT_ICON_ID;
  process.env.TELEGRAM_CATEGORY_LIGHT_ICON_ID = "invalid-custom-id";
  const calls = [];
  const ctx = {session: {}};

  try {
    const result = await renderCategoryRowsWithFallback(
      ctx,
      [categories.light],
      "en",
      (category) => `book:cat:${category.id}`,
      async (rows) => {
        calls.push(rows);
        if (calls.length === 1) throw {error_code: 400, description: "Bad Request"};
        return "ok";
      }
    );

    assert.equal(result, "ok");
    assert.equal(calls.length, 2);
    assert.equal(calls[0][0][0].icon_custom_emoji_id, "invalid-custom-id");
    assert.equal(calls[1][0][0].text, "🛵 Light (100cc-110cc)");
    assert.equal(ctx.session.categoryCustomEmojiFallback, true);
  } finally {
    if (previous === undefined) delete process.env.TELEGRAM_CATEGORY_LIGHT_ICON_ID;
    else process.env.TELEGRAM_CATEGORY_LIGHT_ICON_ID = previous;
  }
});
