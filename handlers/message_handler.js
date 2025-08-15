const db = require("../connect");
const fs = require("fs");
const path = require("path");
const fetch = require("node-fetch");
const {
  updateUserName,
  updateUserPhone,
  updateUserPassportPhoto,
} = require("../services/userService");

const phoneRegex = /^\+\d{10,15}$/;
const nameRegex = /^[a-zA-Zа-яА-ЯёЁ\s\-]{2,}$/;

module.exports = async (ctx) => {
  const step = ctx.session?.step;
  const scenario = ctx.session?.scenario;
  const telegramId = ctx.from.id;

  // 👤 Имя
  if (step === "waiting_for_name") {
    const name = ctx.message?.text?.trim();
    if (!nameRegex.test(name)) {
      return ctx.reply(
        "⚠️ Имя должно содержать только буквы и быть не короче 2 символов. Попробуйте снова."
      );
    }

    const success = await updateUserName(telegramId, name);
    if (!success) {
      return ctx.reply("❌ Не удалось сохранить имя. Попробуйте снова.");
    }

    ctx.session.step = null;

    await ctx.reply("✅ Имя успешно сохранено.");

    if (scenario === "registration") {
      ctx.session.step = "waiting_for_phone";
      return ctx.reply(
        "📞 Пожалуйста, введите номер телефона в международном формате (например, +79995551234):"
      );
    } else {
      return require("../commands/start")(ctx);
    }
  }

  // 📞 Телефон
  if (step === "waiting_for_phone") {
    const phone = ctx.message?.text?.trim();
    if (!phoneRegex.test(phone)) {
      return ctx.reply("⚠️ Неверный формат номера. Пример: +12345678900");
    }

    const success = await updateUserPhone(telegramId, phone);
    if (!success) {
      return ctx.reply("❌ Не удалось сохранить номер. Попробуйте снова.");
    }

    ctx.session.step = null;

    await ctx.reply("✅ Номер телефона успешно сохранён.");

    if (scenario === "registration") {
      ctx.session.step = "waiting_for_passport";
      return ctx.reply("📸 Пожалуйста, отправьте фото паспорта:");
    } else {
      return require("../commands/start")(ctx);
    }
  }

  // 🖼 Паспорт
  if (step === "waiting_for_passport") {
    const photo = ctx.message?.photo?.pop();
    if (!photo) {
      return ctx.reply(
        "⚠️ Пожалуйста, отправьте фото (не документ, не текст)."
      );
    }

    try {
      const file = await ctx.api.getFile(photo.file_id);
      const filePath = file.file_path;
      const ext = path.extname(filePath) || ".jpg";
      const filename = `${telegramId}_passport${ext}`;
      const localPath = path.join("images", filename);
      const url = `https://api.telegram.org/file/bot${process.env.BOT_TOKEN}/${filePath}`;
      const res = await fetch(url);
      const buffer = await res.arrayBuffer();
      fs.writeFileSync(localPath, Buffer.from(buffer));

      const success = await updateUserPassportPhoto(telegramId, filename);
      if (!success) {
        return ctx.reply("❌ Не удалось сохранить фото. Попробуйте снова.");
      }

      ctx.session.step = null;
      ctx.session.scenario = null;

      await ctx.reply("✅ Фото паспорта успешно сохранено.");
      return require("../commands/start")(ctx);
    } catch (err) {
      console.error("Ошибка загрузки файла:", err);
      return ctx.reply("❌ Ошибка при загрузке фото. Попробуйте снова.");
    }
  }
};
