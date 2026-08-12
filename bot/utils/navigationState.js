const DEFAULT_NAVIGATION_STACK_LIMIT = 10;
const MAX_NAVIGATION_STACK_LIMIT = 50;
const NAVIGATION_MODES = new Set(["push", "replace", "back", "reset"]);
const UI_MESSAGE_TYPES = new Set(["text", "photo"]);

function assertSession(session) {
  if (!session || typeof session !== "object" || Array.isArray(session)) {
    throw new TypeError("A bot session object is required");
  }

  return session;
}

function navigationStackLimit(value = DEFAULT_NAVIGATION_STACK_LIMIT) {
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed < 1) {
    return DEFAULT_NAVIGATION_STACK_LIMIT;
  }

  return Math.min(parsed, MAX_NAVIGATION_STACK_LIMIT);
}

function validScreen(screen) {
  return typeof screen === "string" && screen.trim() !== "";
}

function ensureNavigationStack(session) {
  assertSession(session);

  if (!Array.isArray(session.navigationStack)) {
    session.navigationStack = [];
  }

  session.navigationStack = session.navigationStack.filter(
    (entry) => entry && typeof entry === "object" && validScreen(entry.screen)
  );

  return session.navigationStack;
}

function navigationEntry(screen, returnContext) {
  return {
    screen: screen.trim(),
    returnContext: returnContext ?? null,
  };
}

function commitNavigationState(
  session,
  {
    screen,
    returnContext = null,
    mode = "push",
    stackLimit = DEFAULT_NAVIGATION_STACK_LIMIT,
  }
) {
  assertSession(session);
  if (!validScreen(screen)) {
    throw new TypeError("A non-empty screen identifier is required");
  }
  if (!NAVIGATION_MODES.has(mode)) {
    throw new TypeError(`Unsupported navigation mode: ${mode}`);
  }

  const stack = ensureNavigationStack(session);
  const normalizedScreen = screen.trim();

  if (
    mode === "push" &&
    validScreen(session.currentScreen) &&
    session.currentScreen !== normalizedScreen
  ) {
    stack.push(navigationEntry(session.currentScreen, session.returnContext));
  } else if (mode === "back") {
    stack.pop();
  } else if (mode === "reset") {
    stack.length = 0;
  }

  const limit = navigationStackLimit(stackLimit);
  if (stack.length > limit) {
    stack.splice(0, stack.length - limit);
  }

  session.currentScreen = normalizedScreen;
  session.returnContext = returnContext ?? null;

  return getNavigationState(session);
}

function peekNavigationEntry(session) {
  const stack = ensureNavigationStack(session);
  const entry = stack.at(-1);

  return entry
    ? {screen: entry.screen, returnContext: entry.returnContext ?? null}
    : null;
}

function getNavigationState(session) {
  assertSession(session);
  const stack = ensureNavigationStack(session);

  return {
    currentScreen: validScreen(session.currentScreen)
      ? session.currentScreen
      : null,
    returnContext: session.returnContext ?? null,
    navigationStack: stack.map((entry) => ({
      screen: entry.screen,
      returnContext: entry.returnContext ?? null,
    })),
  };
}

function normalizeMessageId(messageId) {
  const parsed = Number(messageId);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
}

function getActiveUiMessage(session) {
  assertSession(session);
  const messageId = normalizeMessageId(session.activeUiMessageId);
  if (!messageId) return null;

  return {
    messageId,
    type: UI_MESSAGE_TYPES.has(session.activeUiMessageType)
      ? session.activeUiMessageType
      : null,
  };
}

function setActiveUiMessage(session, messageId, type) {
  assertSession(session);
  const normalizedId = normalizeMessageId(messageId);
  if (!normalizedId) {
    throw new TypeError("A positive Telegram message_id is required");
  }
  if (!UI_MESSAGE_TYPES.has(type)) {
    throw new TypeError(`Unsupported UI message type: ${type}`);
  }

  session.activeUiMessageId = normalizedId;
  session.activeUiMessageType = type;

  return {messageId: normalizedId, type};
}

function clearActiveUiMessage(session, expectedMessageId = null) {
  assertSession(session);
  const active = getActiveUiMessage(session);
  const expected = normalizeMessageId(expectedMessageId);

  if (expected && active?.messageId !== expected) return false;

  session.activeUiMessageId = null;
  session.activeUiMessageType = null;
  return true;
}

module.exports = {
  DEFAULT_NAVIGATION_STACK_LIMIT,
  MAX_NAVIGATION_STACK_LIMIT,
  clearActiveUiMessage,
  commitNavigationState,
  getActiveUiMessage,
  getNavigationState,
  navigationStackLimit,
  peekNavigationEntry,
  setActiveUiMessage,
};
