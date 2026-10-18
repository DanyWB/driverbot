function vehicleDescription(vehicle, language) {
  const locale = language === "uk" ? "ua" : language;
  for (const value of [vehicle?.descriptions?.[locale], vehicle?.descriptions?.en, vehicle?.description]) {
    if (typeof value === "string" && value.trim()) return value.trim();
  }
  return "";
}

module.exports = {vehicleDescription};
