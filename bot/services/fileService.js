const fs = require("fs");
const path = require("path");
const {positiveInteger} = require("../config/runtime");

const MIME_TYPES = {
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".webp": "image/webp",
  ".pdf": "application/pdf",
};

function fileError(code, message) {
  const error = new Error(message);
  error.code = code;
  return error;
}

async function readLimited(response, maxBytes) {
  const declaredLength = Number(response.headers.get("content-length"));
  if (Number.isFinite(declaredLength) && declaredLength > maxBytes) {
    throw fileError("telegram_file_too_large", "Telegram file exceeds the upload limit");
  }

  if (!response.body) return Buffer.alloc(0);
  const reader = response.body.getReader();
  const chunks = [];
  let total = 0;

  while (true) {
    const {done, value} = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > maxBytes) {
      await reader.cancel();
      throw fileError("telegram_file_too_large", "Telegram file exceeds the upload limit");
    }
    chunks.push(Buffer.from(value));
  }

  return Buffer.concat(chunks, total);
}

async function downloadTelegramFile(api, fileId, localFilename, {persist = true} = {}) {
  const file = await api.getFile(fileId);
  const filePath = file.file_path;
  const ext = path.extname(filePath).toLowerCase() || ".jpg";
  const filename = localFilename.endsWith(ext) ? localFilename : `${localFilename}${ext}`;
  const url = `https://api.telegram.org/file/bot${process.env.BOT_TOKEN}/${filePath}`;
  const controller = new AbortController();
  const timeout = setTimeout(
    () => controller.abort(),
    positiveInteger("BOT_FILE_DOWNLOAD_TIMEOUT_MS", 15000)
  );
  const maxBytes = positiveInteger("BOT_DOCUMENT_MAX_MB", 10) * 1024 * 1024;
  let response;
  let buffer;

  try {
    response = await fetch(url, {signal: controller.signal});
    if (!response.ok) throw new Error(`Telegram file download failed with ${response.status}`);
    buffer = await readLimited(response, maxBytes);
  } finally {
    clearTimeout(timeout);
  }
  let localPath = null;

  if (persist) {
    localPath = path.join("images", filename);
    fs.writeFileSync(localPath, buffer);
  }

  return {
    buffer,
    filename,
    localPath,
    mimeType: response.headers.get("content-type") || MIME_TYPES[ext] || "application/octet-stream",
  };
}

module.exports = {downloadTelegramFile};
