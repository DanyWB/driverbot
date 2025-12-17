const fs = require("fs");
const path = require("path");
const fetch = require("node-fetch");

async function downloadTelegramFile(api, fileId, localFilename) {
  const file = await api.getFile(fileId);
  const filePath = file.file_path;
  const ext = path.extname(filePath) || ".jpg";
  const filename = localFilename.endsWith(ext)
    ? localFilename
    : `${localFilename}${ext}`;
  const localPath = path.join("images", filename);

  const url = `https://api.telegram.org/file/bot${process.env.BOT_TOKEN}/${filePath}`;
  const response = await fetch(url);
  const buffer = await response.arrayBuffer();
  fs.writeFileSync(localPath, Buffer.from(buffer));

  return {filename, localPath};
}

module.exports = {downloadTelegramFile};
