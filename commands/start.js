const db = require("../connect");
const {registerUser} = require("../services/userService");
const {setUserCommands} = require("../utils/setCommands");
const {getMainMenuKeyboard} = require("../utils/mainMenu");
const {
  t,
  getLanguageKeyboard,
  isSupportedLang,
  normalizeLang,
} = require("../utils/i18n");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;

  let user = await db("users").where({telegram_id: telegramId}).first();
  let isNewUser = false;

  if (!user) {
    await registerUser({
      id: telegramId,
      username: ctx.from.username || null,
    });

    user = await db("users").where({telegram_id: telegramId}).first();
    isNewUser = true;
  }

  if (isNewUser) {
    ctx.session.justRegistered = true;
  }

  if (isNewUser || !isSupportedLang(user.lang)) {
    await ctx.reply(t("ru", "lang_prompt"), {
      reply_markup: getLanguageKeyboard(),
    });
    return;
  }

  const lang = normalizeLang(user.lang);
  ctx.session.lang = lang;

  await setUserCommands(user, ctx, lang);

  const isFirstVisit = Boolean(ctx.session.justRegistered);
  if (isFirstVisit) {
    ctx.session.justRegistered = null;
  }

  if (isFirstVisit) {
    await ctx.reply(
      t(lang, "welcome_new", {
        name: ctx.from.first_name || t(lang, "user_default_name"),
      })
    );
  } else {
    await ctx.reply(
      t(lang, "welcome_back", {
        name: user.name || ctx.from.first_name || t(lang, "user_default_name"),
      })
    );
  }

  const isNameOk = !!user.name;
  const isPhoneOk = !!user.phone;
  const passportNumber = user.meta?.passport_number;
  const isPassportOk = !!(user.passport_photo_file_id || passportNumber);

  if (!isNameOk) {
    ctx.session.step = "waiting_for_name";
    ctx.session.scenario = "registration";
    return ctx.reply(t(lang, "enter_name"));
  }

  if (!isPhoneOk) {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    return ctx.reply(t(lang, "enter_phone"));
  }

  if (!isPassportOk) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    return ctx.reply(t(lang, "enter_passport"));
  }

  return ctx.reply(t(lang, "menu_title"), {
    reply_markup: getMainMenuKeyboard(lang),
  });
};
