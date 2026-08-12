const {SUPPORTED_LANGS, t, normalizeLang} = require("./i18n");

const QUICK_ACTIONS = {
  book: "quick_book",
  prices: "quick_prices",
};

const INLINE_MENU_ACTIONS = [
  ["book", "main_menu_book"],
  ["bookings", "main_menu_bookings"],
  ["prices", "menu_prices"],
  ["conditions", "menu_conditions"],
  ["profile", "menu_account"],
  ["support", "menu_support"],
  ["about", "menu_about"],
];

function getMainMenuKeyboard(lang) {
  const normalized = normalizeLang(lang);
  return {
    keyboard: [[
      {text: t(normalized, QUICK_ACTIONS.book)},
      {text: t(normalized, QUICK_ACTIONS.prices)},
    ]],
    resize_keyboard: true,
    one_time_keyboard: false,
    is_persistent: true,
  };
}

function getInlineMainMenuKeyboard(lang) {
  const normalized = normalizeLang(lang);
  const button = ([action, key]) => ({
    text: t(normalized, key),
    callback_data: `menu:${action}`,
  });

  return {
    inline_keyboard: [
      [button(INLINE_MENU_ACTIONS[0])],
      [button(INLINE_MENU_ACTIONS[1])],
      [button(INLINE_MENU_ACTIONS[2]), button(INLINE_MENU_ACTIONS[3])],
      [button(INLINE_MENU_ACTIONS[4]), button(INLINE_MENU_ACTIONS[5])],
      [button(INLINE_MENU_ACTIONS[6])],
    ],
  };
}

function detectMainMenuAction(text) {
  if (!text) return null;
  for (const [action, key] of Object.entries(QUICK_ACTIONS)) {
    for (const lang of SUPPORTED_LANGS) {
      if (t(lang, key) === text) return action;
    }
  }
  return null;
}

module.exports = {
  getInlineMainMenuKeyboard,
  getMainMenuKeyboard,
  detectMainMenuAction,
  INLINE_MENU_ACTIONS,
  QUICK_ACTIONS,
};
