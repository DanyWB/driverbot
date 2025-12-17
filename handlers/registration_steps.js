const {downloadTelegramFile} = require("../services/fileService");
const {
  updateUserName,
  updateUserPhone,
  updateUserPassportPhoto,
  getUserByTelegramId,
} = require("../services/userService");
const {isValidName, isValidPhone} = require("../utils/validators");

async function handleNameStep(ctx) {
  const name = ctx.message?.text?.trim() || "";
  const fromId = ctx.from.id;

  if (!isValidName(name)) {
    await ctx.reply(
      "Имя должно содержать только буквы и быть не короче 2 символов. Попробуйте снова."
    );
    return true;
  }

  const success = await updateUserName(fromId, name);
  if (!success) {
    await ctx.reply("Не удалось сохранить имя. Попробуйте снова.");
    return true;
  }

  await ctx.reply("Имя успешно сохранено.");

  if (ctx.session?.scenario === "registration") {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    await ctx.reply(
      "Пожалуйста, введите номер телефона в международном формате (например, +79995551234):"
    );
    return true;
  }

  ctx.session.step = null;
  ctx.session.scenario = null;
  await require("../commands/start")(ctx);
  return true;
}

async function handlePhoneStep(ctx) {
  const phone = ctx.message?.text?.trim() || "";
  const fromId = ctx.from.id;

  if (!isValidPhone(phone)) {
    await ctx.reply("Неверный формат номера. Пример: +12345678900");
    return true;
  }

  const success = await updateUserPhone(fromId, phone);
  if (!success) {
    await ctx.reply("Не удалось сохранить номер. Попробуйте снова.");
    return true;
  }

  await ctx.reply("Номер телефона успешно сохранён.");

  const user = await getUserByTelegramId(fromId);
  if (ctx.session?.scenario === "registration" || !user.passport_photo_file_id) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    await ctx.reply("Пожалуйста, отправьте фото паспорта:");
    return true;
  }

  ctx.session.step = null;
  ctx.session.scenario = null;
  await require("../commands/start")(ctx);
  return true;
}

async function handlePassportStep(ctx) {
  const photo = ctx.message?.photo?.pop();
  const fromId = ctx.from.id;

  if (!photo) {
    await ctx.reply("Пожалуйста, отправьте фото (не документ, не текст).");
    return true;
  }

  try {
    const {filename} = await downloadTelegramFile(
      ctx.api,
      photo.file_id,
      `${fromId}_passport`
    );

    const success = await updateUserPassportPhoto(fromId, filename);
    if (!success) {
      await ctx.reply("Не удалось сохранить фото. Попробуйте снова.");
      return true;
    }

    await ctx.reply("Фото паспорта успешно сохранено.");

    ctx.session.step = null;
    ctx.session.scenario = null;
    await require("../commands/start")(ctx);
    return true;
  } catch (err) {
    console.error("Ошибка загрузки файла:", err);
    await ctx.reply("Произошла ошибка при получении фото. Попробуйте снова.");
    return true;
  }
}

module.exports = {handleNameStep, handlePhoneStep, handlePassportStep};
