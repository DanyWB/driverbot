const {
  updateUserPhone,
  getUserByTelegramId,
} = require("../services/userService");

const phoneRegex = /^\+\d{10,15}$/;

module.exports = async (ctx) => {
  const phone = ctx.message?.text?.trim();
  const fromId = ctx.from.id;

  if (!phoneRegex.test(phone)) {
    return ctx.reply("⚠️ Неверный формат номера. Пример: +12345678900");
  }

  const success = await updateUserPhone(fromId, phone);
  if (!success) {
    return ctx.reply("❌ Не удалось сохранить номер. Попробуйте снова.");
  }

  await ctx.reply("✅ Номер телефона успешно сохранён.");

  const user = await getUserByTelegramId(fromId);
  if (
    ctx.session?.scenario === "registration" ||
    !user.passport_photo_file_id
  ) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    return ctx.reply("🛂 Пожалуйста, отправьте фото паспорта:");
  }

  ctx.session.step = null;
  ctx.session.scenario = null;
  return require("../commands/start")(ctx);
};
