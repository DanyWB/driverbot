function preview(value, maxLength = 140) {
  const characters = Array.from(String(value || "").trim());
  return characters.length <= maxLength
    ? characters.join("")
    : `${characters.slice(0, Math.max(0, maxLength - 3)).join("")}...`;
}

module.exports = {preview};
