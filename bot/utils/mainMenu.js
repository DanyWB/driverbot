const {SUPPORTED_LANGS, t, normalizeLang} = require("./i18n");

const MENU_ACTIONS = {
  rent: "menu_my_rent",
  support: "menu_support",
  prices: "menu_prices",
  account: "menu_account",
  conditions: "menu_conditions",
  about: "menu_about",
};

function getMainMenuKeyboard(lang) {
  const normalized = normalizeLang(lang);
  const labels = Object.fromEntries(
    Object.entries(MENU_ACTIONS).map(([action, key]) => [
      action,
      t(normalized, key),
    ])
  );

  return {
    keyboard: [
      [
        {text: labels.rent},
        {text: labels.support},
      ],
      [
        {text: labels.prices},
        {text: labels.account},
      ],
      [
        {text: labels.conditions},
        {text: labels.about},
      ],
    ],
    resize_keyboard: true,
    one_time_keyboard: false,
    input_field_placeholder: "",
  };
}

function detectMainMenuAction(text) {
  if (!text) return null;
  for (const [action, key] of Object.entries(MENU_ACTIONS)) {
    for (const lang of SUPPORTED_LANGS) {
      if (t(lang, key) === text) {
        return action;
      }
    }
  }
  return null;
}

module.exports = {getMainMenuKeyboard, detectMainMenuAction, MENU_ACTIONS};
