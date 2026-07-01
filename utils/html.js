const {t} = require("./i18n");

function escapeHtml(value) {
  if (value === null || value === undefined) return "";
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function escapeHtmlVars(vars = {}) {
  return Object.fromEntries(
    Object.entries(vars).map(([key, value]) => [key, escapeHtml(value)])
  );
}

function tHtml(lang, key, vars = {}) {
  return t(lang, key, escapeHtmlVars(vars));
}

module.exports = {escapeHtml, escapeHtmlVars, tHtml};
