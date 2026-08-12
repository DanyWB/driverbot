const assert = require("node:assert/strict");
const test = require("node:test");
const {
  clearActiveUiMessage,
  commitNavigationState,
  getActiveUiMessage,
  getNavigationState,
  navigationStackLimit,
  peekNavigationEntry,
  setActiveUiMessage,
} = require("../utils/navigationState");

test("keeps a bounded navigation stack with return contexts", () => {
  const session = {};

  commitNavigationState(session, {
    screen: "main_menu",
    returnContext: {source: "start"},
    stackLimit: 3,
  });
  commitNavigationState(session, {
    screen: "prices",
    returnContext: {season: "high"},
    stackLimit: 3,
  });
  commitNavigationState(session, {
    screen: "conditions",
    returnContext: {origin: "main_menu"},
    stackLimit: 3,
  });
  commitNavigationState(session, {
    screen: "profile",
    returnContext: null,
    stackLimit: 3,
  });
  commitNavigationState(session, {
    screen: "support",
    returnContext: {section: "faq"},
    stackLimit: 3,
  });

  assert.deepEqual(getNavigationState(session), {
    currentScreen: "support",
    returnContext: {section: "faq"},
    navigationStack: [
      {screen: "prices", returnContext: {season: "high"}},
      {screen: "conditions", returnContext: {origin: "main_menu"}},
      {screen: "profile", returnContext: null},
    ],
  });
  assert.deepEqual(peekNavigationEntry(session), {
    screen: "profile",
    returnContext: null,
  });
});

test("does not add duplicate stack entries and supports back/reset transitions", () => {
  const session = {};
  commitNavigationState(session, {screen: "main_menu"});
  commitNavigationState(session, {screen: "prices"});
  commitNavigationState(session, {screen: "prices", returnContext: {season: "low"}});

  assert.equal(session.navigationStack.length, 1);
  assert.deepEqual(peekNavigationEntry(session), {
    screen: "main_menu",
    returnContext: null,
  });

  commitNavigationState(session, {
    screen: "main_menu",
    mode: "back",
  });
  assert.deepEqual(session.navigationStack, []);

  commitNavigationState(session, {screen: "support"});
  commitNavigationState(session, {screen: "main_menu", mode: "reset"});
  assert.deepEqual(session.navigationStack, []);
});

test("normalizes stack limits and active UI message state", () => {
  const session = {};

  assert.equal(navigationStackLimit(0), 10);
  assert.equal(navigationStackLimit(500), 50);
  assert.equal(getActiveUiMessage(session), null);

  assert.deepEqual(setActiveUiMessage(session, "123", "photo"), {
    messageId: 123,
    type: "photo",
  });
  assert.deepEqual(getActiveUiMessage(session), {
    messageId: 123,
    type: "photo",
  });
  assert.equal(clearActiveUiMessage(session, 999), false);
  assert.equal(getActiveUiMessage(session).messageId, 123);
  assert.equal(clearActiveUiMessage(session, 123), true);
  assert.equal(getActiveUiMessage(session), null);
});
