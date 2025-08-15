const fs = require("fs");
const path = require("path");
const {updateUserPassportPhoto} = require("../services/userService");

module.exports = async (ctx) => {
  const photo = ctx.message?.photo?.pop();
  const fromId = ctx.from.id;

  if (!photo) {
    return ctx.reply("⚠️ Пожалуйста, отправьте фото (не документ, не текст).");
  }

  try {
    const file = await ctx.api.getFile(photo.file_id);
    const filePath = file.file_path;
    const fileExt = path.extname(filePath) || ".jpg";
    const filename = `${fromId}_passport${fileExt}`;
    const localPath = path.join("images", filename);

    const url = `https://api.telegram.org/file/bot${process.env.BOT_TOKEN}/${filePath}`;
    const response = await fetch(url);
    const buffer = await response.arrayBuffer();
    fs.writeFileSync(localPath, Buffer.from(buffer));

    const success = await updateUserPassportPhoto(fromId, filename);
    if (!success) {
      return ctx.reply("❌ Не удалось сохранить фото. Попробуйте снова.");
    }

    await ctx.reply("✅ Фото паспорта успешно сохранено.");

    ctx.session.step = null;
    ctx.session.scenario = null;
    return require("../commands/start")(ctx);
  } catch (err) {
    console.error("Ошибка загрузки файла:", err);
    return ctx.reply(
      "❌ Произошла ошибка при получении фото. Попробуйте снова."
    );
  }
};
