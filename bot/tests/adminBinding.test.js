const assert = require("node:assert/strict");
const test = require("node:test");
const {t} = require("../utils/i18n");
const bindCommand = require("../commands/bind");
const {setUserCommands} = require("../utils/setCommands");

function context({lang = "en", chatType = "private", code = "ABCD-EFGH"} = {}) {
  const replies = [];
  const deletedMessages = [];
  const ctx = {
    from: {
      id: 12345,
      username: "admin_user",
      first_name: "Admin",
      language_code: lang === "ua" ? "uk-UA" : lang,
    },
    chat: {id: chatType === "private" ? 12345 : -100500, type: chatType},
    message: {chat: {id: chatType === "private" ? 12345 : -100500, type: chatType}},
    session: lang ? {lang} : {},
    update: {update_id: 77},
    match: code,
    async reply(text) {
      replies.push(text);
      return text;
    },
    async deleteMessage() {
      deletedMessages.push(true);
    },
  };

  return {ctx, replies, deletedMessages};
}

test("bind command requires a code and handles a valid code exposed in a group", async () => {
  let calls = 0;
  const binder = async () => { calls += 1; };

  const missing = context({lang: "en", code: ""});
  await bindCommand.handleBind(missing.ctx, "", {bindAdminTelegram: binder});
  assert.deepEqual(missing.replies, [t("en", "telegram_bind_usage")]);

  const malformed = context({lang: "en", code: "not a code"});
  await bindCommand.handleBind(malformed.ctx, "not a code", {bindAdminTelegram: binder});
  assert.deepEqual(malformed.replies, [t("en", "telegram_bind_usage")]);

  const group = context({lang: "en", chatType: "group"});
  const revoked = [];
  let deleteStartedBeforeRevokeFinished = false;
  await bindCommand.handleBind(group.ctx, "ABCD-EFGH", {
    bindAdminTelegram: binder,
    async revokeExposedCode(ctx, code) {
      revoked.push({ctx, code});
      await new Promise((resolve) => setImmediate(resolve));
      deleteStartedBeforeRevokeFinished = group.deletedMessages.length === 1;
    },
  });
  assert.deepEqual(group.replies, [
    t("en", "telegram_bind_exposed_revocation_attempted"),
  ]);
  assert.deepEqual(revoked, [{ctx: group.ctx, code: "ABCD-EFGH"}]);
  assert.equal(group.deletedMessages.length, 1);
  assert.equal(deleteStartedBeforeRevokeFinished, true);
  assert.equal(calls, 0);
});

test("an exposed-code revoke failure is reported without claiming success", async () => {
  const logged = [];
  const group = context({lang: "ru", chatType: "group"});

  await bindCommand.handleBind(group.ctx, "ABCD-EFGH", {
    async revokeExposedCode() {
      throw {code: "BOT_API_UNAVAILABLE", status: 503, requestId: "request-2"};
    },
    logger: {error(...args) { logged.push(args); }},
  });

  assert.deepEqual(group.replies, [
    t("ru", "telegram_bind_exposed_revocation_failed"),
  ]);
  assert.equal(group.deletedMessages.length, 1);
  assert.equal(logged.length, 1);
});

test("bind command links a private Telegram chat and replies in its preferred language", async () => {
  const calls = [];
  const {ctx, replies} = context({lang: "ua"});

  await bindCommand.handleBind(ctx, "ABCD-EFGH", {
    async bindAdminTelegram(receivedCtx, code) {
      calls.push({receivedCtx, code});
    },
  });

  assert.equal(calls.length, 1);
  assert.equal(calls[0].receivedCtx, ctx);
  assert.equal(calls[0].code, "ABCD-EFGH");
  assert.deepEqual(replies, [t("ua", "telegram_bind_success")]);
});

test("bind command infers Ukrainian from Telegram when no saved language exists", async () => {
  const {ctx, replies} = context({lang: ""});
  ctx.from.language_code = "uk-UA";

  await bindCommand.handleBind(ctx, "abcd-efgh", {
    async bindAdminTelegram() {},
  });

  assert.deepEqual(replies, [t("ua", "telegram_bind_success")]);
});

test("bind command maps stable API failures without exposing backend messages", async () => {
  const cases = [
    ["TELEGRAM_BINDING_CODE_INVALID", 422, "telegram_bind_invalid"],
    ["TELEGRAM_ACCOUNT_ALREADY_BOUND", 409, "telegram_bind_already_bound"],
    ["ADMIN_ACCOUNT_INACTIVE", 403, "telegram_bind_admin_inactive"],
    ["RATE_LIMIT_EXCEEDED", 429, "telegram_bind_rate_limited"],
  ];

  for (const [code, status, messageKey] of cases) {
    const {ctx, replies} = context({lang: "en"});
    await bindCommand.handleBind(ctx, "ABCD-EFGH", {
      async bindAdminTelegram() {
        throw {code, status, message: "sensitive backend detail"};
      },
    });
    assert.deepEqual(replies, [t("en", messageKey)]);
    assert.equal(replies[0].includes("sensitive backend detail"), false);
  }

  const logged = [];
  const unavailable = context({lang: "en"});
  await bindCommand.handleBind(unavailable.ctx, "ABCD-EFGH", {
    async bindAdminTelegram() {
      throw {
        code: "BOT_API_UNAVAILABLE",
        status: 503,
        message: "database password is secret",
        requestId: "request-1",
      };
    },
    logger: {error(...args) { logged.push(args); }},
  });

  assert.deepEqual(unavailable.replies, [t("en", "telegram_bind_unavailable")]);
  assert.equal(JSON.stringify(unavailable.replies).includes("database password"), false);
  assert.equal(JSON.stringify(logged).includes("database password"), false);
  assert.equal(logged.length, 1);
});

test("start bind payload delegates directly to binding without customer registration", async () => {
  const start = require("../commands/start");
  const bindModule = require("../commands/bind");
  const originalHandleBind = bindModule.handleBind;
  const delegated = [];

  bindModule.handleBind = async (ctx, code) => {
    delegated.push({ctx, code});
  };

  try {
    const {ctx} = context({lang: "en"});
    ctx.match = "bind_ABCD-EFGH";
    ctx.message.text = "/start bind_ABCD-EFGH";
    await start(ctx);

    const fallback = context({lang: "en"}).ctx;
    fallback.match = undefined;
    fallback.message.text = "/start@DrivePhanganBot bind_WXYZ-2345";
    await start(fallback);

    assert.deepEqual(delegated, [
      {ctx, code: "ABCD-EFGH"},
      {ctx: fallback, code: "WXYZ-2345"},
    ]);
  } finally {
    bindModule.handleBind = originalHandleBind;
  }
});

test("start payload parser ignores unrelated payloads and recognizes empty bind payload", () => {
  const {startBindingCode} = require("../commands/start");

  assert.equal(startBindingCode({match: "campaign_123"}), null);
  assert.equal(startBindingCode({match: "bind_ABCD-EFGH"}), "ABCD-EFGH");
  assert.equal(startBindingCode({match: "bind_"}), "");
});

test("bind remains hidden from public and legacy admin command menus", async () => {
  for (const isAdmin of [false, true]) {
    let commands = [];
    const ctx = {
      api: {
        async deleteMyCommands() {},
        async setMyCommands(value) { commands = value; },
      },
    };

    await setUserCommands({telegram_id: 12345, lang: "en", is_admin: isAdmin}, ctx);
    assert.equal(commands.some(({command}) => command === "bind"), false);
    assert.equal(commands.some(({command}) => command === "menu"), true);
  }
});
