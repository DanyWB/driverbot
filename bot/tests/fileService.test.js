const assert = require("node:assert/strict");
const test = require("node:test");
const {downloadTelegramFile} = require("../services/fileService");

test("downloads Telegram files within the limit and rejects oversized content", async () => {
  const originalFetch = global.fetch;
  const originalLimit = process.env.BOT_DOCUMENT_MAX_MB;
  process.env.BOT_DOCUMENT_MAX_MB = "1";
  process.env.BOT_TOKEN = "test-token";
  const api = {getFile: async () => ({file_path: "documents/passport.jpg"})};

  try {
    global.fetch = async () => new Response(Buffer.from("image"), {
      status: 200,
      headers: {"content-type": "image/jpeg", "content-length": "5"},
    });
    const file = await downloadTelegramFile(api, "file-1", "123_passport", {persist: false});
    assert.equal(file.buffer.toString(), "image");
    assert.equal(file.filename, "123_passport.jpg");

    global.fetch = async () => new Response(Buffer.from("x"), {
      status: 200,
      headers: {"content-length": String(2 * 1024 * 1024)},
    });
    await assert.rejects(
      downloadTelegramFile(api, "file-2", "123_passport", {persist: false}),
      (error) => error.code === "telegram_file_too_large"
    );
  } finally {
    global.fetch = originalFetch;
    if (originalLimit === undefined) delete process.env.BOT_DOCUMENT_MAX_MB;
    else process.env.BOT_DOCUMENT_MAX_MB = originalLimit;
  }
});
