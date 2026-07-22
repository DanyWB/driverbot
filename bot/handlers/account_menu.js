const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml, tHtml} = require("../utils/html");
const {getUserByTelegramId} = require("../services/userService");

function normalizeMeta(meta) {
  if (!meta) return {};
  if (typeof meta === "object") return meta;
  if (typeof meta === "string") {
    try {
      return JSON.parse(meta);
    } catch (e) {
      return {};
    }
  }
  return {};
}

function formatAccountText(user, lang) {
  const name = user.name || t(lang, "account_value_missing");
  const phone = user.phone || t(lang, "account_value_missing");
  const meta = normalizeMeta(user.meta);
  const passportNumber = meta.passport_number;
  const hasPassportPhoto = Boolean(user.passport_photo_file_id);
  let passportValue = t(lang, "account_passport_missing");

  if (passportNumber) {
    passportValue = tHtml(lang, "account_passport_number_value", {
      number: passportNumber,
    });
  } else if (hasPassportPhoto) {
    passportValue = t(lang, "account_passport_photo");
  }

  return [
    `<b>${t(lang, "account_profile_title")}</b>`,
    `${t(lang, "account_name_label")}: ${escapeHtml(name)}`,
    `${t(lang, "account_phone_label")}: ${escapeHtml(phone)}`,
    `${t(lang, "account_passport_label")}: ${passportValue}`,
    "",
    t(lang, "account_profile_hint"),
  ].join("\n");
}

async function sendAccountMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  const user = await getUserByTelegramId(ctx.from.id);
  if (!user) {
    return ctx.reply(t(lang, "not_registered"));
  }

  return ctx.reply(formatAccountText(user, lang), {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "menu_update_name"), callback_data: "update:name"}],
        [{text: t(lang, "menu_update_tel"), callback_data: "update:tel"}],
        [
          {
            text: t(lang, "menu_update_passport"),
            callback_data: "update:passport",
          },
        ],
        [{text: t(lang, "btn_main_menu"), callback_data: "account:back"}],
      ],
    },
  });
}

async function handleAccountAction(ctx) {
  const action = ctx.callbackQuery?.data || "";
  if (action === "account:back") {
    await ctx.answerCallbackQuery();
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return require("../commands/start")(ctx);
  }
}

module.exports = {sendAccountMenu, handleAccountAction};
