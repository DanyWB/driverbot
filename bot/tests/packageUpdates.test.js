const test = require('node:test');
const assert = require('node:assert/strict');
const {availableModelGroups, modelGroup} = require('../utils/modelGroups');
const {showCategory} = require('../handlers/booking_category_list');
const {handleLaravelRentMenuActionWithDeps} = require('../handlers/laravel_rent_menu');
const {buildDraftMenuPayload} = require('../handlers/booking_draft_menu');
const {restoreTermsAcceptance} = require('../services/sessionCartService');

test('model groups keep unknown models accessible and accept hyphenated N-Max', async () => {
  const bikes = ['Aerox 155', 'N-Max 155', 'PCX160', 'ADV 160', 'Custom model'].map((name, id) => ({name, id: id + 1}));
  assert.deepEqual(availableModelGroups(bikes), ['aerox', 'nmax', 'pcx', 'adv', 'other']);
  assert.equal(modelGroup({name: 'Forza'}), 'other');
  const ctx = {session: {lang: 'en', booking: {scenario: 'bike_first'}}};
  let screen;
  const options = {bikes, categories: [{id: 2, code: 'comfort-scooters'}], renderer: {async renderText(c, p) {screen = p;}}};
  await showCategory(ctx, 2, options);
  assert.equal(screen.screen, 'booking_models');
  await showCategory(ctx, 2, {...options, group: 'nmax'});
  assert.equal(screen.replyMarkup.inline_keyboard[0][0].callback_data, 'book:select_bike:2');
  assert.equal(screen.replyMarkup.inline_keyboard.at(-1)[0].callback_data, 'book:cat:2');
  await showCategory(ctx, 2, {...options, preserveGroup: true});
  assert.equal(screen.returnContext.modelGroup, 'nmax');
});

test('payment screen shows two currencies and independent receipts, not a false empty message', async () => {
  let screen;
  await handleLaravelRentMenuActionWithDeps({from: {id: 1}, session: {lang: 'en'}, callbackQuery: {data: 'rent:payment'}}, {
    renderer: {async renderText(c, p) {screen = p;}},
    listBookingsPage: async () => ({total: 1, items: [{booking_public_id: 'test', bike_name: '<PCX>', payments: {
      prepayment: {amount: '1500.00', currency: 'THB', received: true}, deposit: {amount: '200.00', currency: 'USD', received: false},
    }}]}),
  });
  assert.match(screen.text, /1500.00 THB/);
  assert.match(screen.text, /200.00 USD/);
  assert.match(screen.text, /&lt;PCX&gt;/);
  assert.doesNotMatch(screen.text, /No active/);
});

test('draft secondary actions collapse without hiding confirmation or changing cart', async () => {
  const original = process.env.BOT_DATA_MODE;
  process.env.BOT_DATA_MODE = 'laravel';
  try {
    const cart = [{bike_id: 1, name: 'PCX', start_date: '2026-11-01', end_date: '2026-11-03', total_price: 1000}];
    const ctx = {session: {lang: 'en', bookingCart: cart, acceptTerms: true, acceptedTermsVersion: 'v1'}};
    const callbacks = p => p.reply_markup.inline_keyboard.flat().map(b => b.callback_data);
    const collapsed = callbacks(await buildDraftMenuPayload(ctx, {user: {id: 1}}));
    assert.ok(collapsed.includes('book:confirm_rental'));
    assert.ok(collapsed.includes('book:menu'));
    assert.ok(!collapsed.includes('book:delete_bike'));
    ctx.session.draftMenuExpanded = true;
    assert.ok(callbacks(await buildDraftMenuPayload(ctx, {user: {id: 1}})).includes('book:delete_bike'));
    assert.equal(ctx.session.bookingCart, cart);
    restoreTermsAcceptance(ctx, {terms: {version: 'v2', current: false}});
    assert.equal(Boolean(ctx.session.acceptTerms), false);
  } finally { if (original === undefined) delete process.env.BOT_DATA_MODE; else process.env.BOT_DATA_MODE = original; }
});
