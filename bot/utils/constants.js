const ADMIN_USERNAME = "danykrasniy";
const DEFAULT_BIKE_EMOJI = "🟢";

function getAdminTelegramUrl() {
  if (!ADMIN_USERNAME) return null;
  const handle = String(ADMIN_USERNAME).replace(/^@/, "").trim();
  if (!handle) return null;
  return `https://t.me/${handle}`;
}

module.exports = {ADMIN_USERNAME, DEFAULT_BIKE_EMOJI, getAdminTelegramUrl};
