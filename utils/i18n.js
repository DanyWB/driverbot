const dictionaries = require("../langs");

const SUPPORTED_LANGS = ["ru", "en", "ua"];
const DEFAULT_LANG = "ru";
const DAYJS_LOCALES = {ru: "ru", en: "en", ua: "uk"};
const WEEKDAYS = {
  ru: ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"],
  en: ["Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"],
  ua: ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"],
};

function normalizeLang(lang) {
  const code = String(lang || "").toLowerCase();
  return SUPPORTED_LANGS.includes(code) ? code : DEFAULT_LANG;
}

function isSupportedLang(lang) {
  return SUPPORTED_LANGS.includes(String(lang || "").toLowerCase());
}

function getCtxLang(ctx) {
  return normalizeLang(ctx?.session?.lang);
}

function getWeekdays(lang) {
  return WEEKDAYS[normalizeLang(lang)] || WEEKDAYS[DEFAULT_LANG];
}

function getDayjsLocale(lang) {
  return DAYJS_LOCALES[normalizeLang(lang)] || DAYJS_LOCALES[DEFAULT_LANG];
}

function t(lang, key, vars = {}) {
  const resolved = normalizeLang(lang);
  const dict = dictionaries[resolved] || {};
  const fallback = dictionaries[DEFAULT_LANG] || {};
  const template = dict[key] || fallback[key] || key;

  return template.replace(/\{(\w+)\}/g, (_, name) =>
    Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : ""
  );
}

function getLanguageKeyboard() {
  return {
    inline_keyboard: [
      [
        {text: "UA", callback_data: "lang:set:ua"},
        {text: "EN", callback_data: "lang:set:en"},
        {text: "RU", callback_data: "lang:set:ru"},
      ],
    ],
  };
}

function getCalendarLabels(lang) {
  return {
    prev: t(lang, "cal_prev"),
    next: t(lang, "cal_next"),
    back: t(lang, "cal_back"),
    blocked: t(lang, "cal_blocked"),
  };
}

module.exports = {
  SUPPORTED_LANGS,
  DEFAULT_LANG,
  normalizeLang,
  isSupportedLang,
  getCtxLang,
  getWeekdays,
  getDayjsLocale,
  t,
  getLanguageKeyboard,
  getCalendarLabels,
};
