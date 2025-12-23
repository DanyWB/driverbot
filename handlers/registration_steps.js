const {downloadTelegramFile} = require("../services/fileService");
const {
  updateUserName,
  updateUserPhone,
  updateUserPassportPhoto,
  getUserByTelegramId,
} = require("../services/userService");
const {isValidName, isValidPhone} = require("../utils/validators");
const {t, getCtxLang} = require("../utils/i18n");

async function handleNameStep(ctx) {
  const name = ctx.message?.text?.trim() || "";
  const fromId = ctx.from.id;
  const lang = getCtxLang(ctx);

  if (!isValidName(name)) {
    await ctx.reply(t(lang, "name_invalid"));
    return true;
  }

  const success = await updateUserName(fromId, name);
  if (!success) {
    await ctx.reply(t(lang, "name_save_error"));
    return true;
  }

  await ctx.reply(t(lang, "name_saved"));

  if (ctx.session?.scenario === "registration") {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    await ctx.reply(t(lang, "enter_phone"));
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
  const lang = getCtxLang(ctx);

  if (!isValidPhone(phone)) {
    await ctx.reply(t(lang, "phone_invalid"));
    return true;
  }

  const success = await updateUserPhone(fromId, phone);
  if (!success) {
    await ctx.reply(t(lang, "phone_save_error"));
    return true;
  }

  await ctx.reply(t(lang, "phone_saved"));

  const user = await getUserByTelegramId(fromId);
  if (ctx.session?.scenario === "registration" || !user.passport_photo_file_id) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    await ctx.reply(t(lang, "enter_passport"));
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
  const lang = getCtxLang(ctx);

  if (!photo) {
    await ctx.reply(t(lang, "passport_missing"));
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
      await ctx.reply(t(lang, "passport_save_error"));
      return true;
    }

    await ctx.reply(t(lang, "passport_saved"));

    ctx.session.step = null;
    ctx.session.scenario = null;
    await require("../commands/start")(ctx);
    return true;
  } catch (err) {
    console.error("Ошибка загрузки файла:", err);
    await ctx.reply(t(lang, "passport_download_error"));
    return true;
  }
}

module.exports = {handleNameStep, handlePhoneStep, handlePassportStep};
