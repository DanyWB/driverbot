const DEFAULT_BIKE_EMOJI = "🟢";

function telegramUsernameUrl(username) {
  if (!username) return null;
  const handle = String(username).replace(/^@/, "").trim();
  if (!handle) return null;
  return `https://t.me/${handle}`;
}

module.exports = {DEFAULT_BIKE_EMOJI, telegramUsernameUrl};
