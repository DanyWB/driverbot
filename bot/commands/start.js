const {registerUser, getUserByTelegramId} = require("../services/userService");
const {isLaravelMode} = require("../config/runtime");
const {setUserCommands} = require("../utils/setCommands");
const {getMainMenuKeyboard} = require("../utils/mainMenu");
const {botScreenRenderer} = require("../services/botScreenRenderer");
const {showMainMenu} = require("../handlers/main_menu");
const {
  t,
  getLanguageKeyboard,
  isSupportedLang,
  normalizeLang,
} = require("../utils/i18n");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;
  let user = null;
  try {
    user = await getUserByTelegramId(telegramId);
  } catch (error) {
    if (!isLaravelMode() || error.code !== "CUSTOMER_NOT_SYNCED") throw error;
  }
  let isNewUser = false;

  if (!user) {
    user = await registerUser(ctx.from);
    isNewUser = true;
  } else if (isLaravelMode()) {
    user = await registerUser(ctx.from);
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
      }),
      {reply_markup: getMainMenuKeyboard(lang)}
    );
  } else {
    await ctx.reply(
      t(lang, "welcome_back", {
        name: user.name || ctx.from.first_name || t(lang, "user_default_name"),
      }),
      {reply_markup: getMainMenuKeyboard(lang)}
    );
  }

  const isNameOk = !!user.name;
  const isPhoneOk = !!user.phone;
  const passportNumber = user.meta?.passport_number;
  const isPassportOk = !!(user.passport_photo_file_id || passportNumber);

  if (!isNameOk) {
    ctx.session.step = "waiting_for_name";
    ctx.session.scenario = "registration";
    return botScreenRenderer.renderText(ctx, {
      screen: "registration_name",
      text: t(lang, "enter_name"),
      navigationMode: "reset",
    });
  }

  if (!isPhoneOk) {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    return botScreenRenderer.renderText(ctx, {
      screen: "registration_phone",
      text: t(lang, "enter_phone"),
      navigationMode: "reset",
    });
  }

  if (!isPassportOk) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    return botScreenRenderer.renderText(ctx, {
      screen: "registration_passport",
      text: t(lang, "enter_passport"),
      navigationMode: "reset",
    });
  }

  return showMainMenu(ctx, lang, {navigationMode: "reset"});
};
