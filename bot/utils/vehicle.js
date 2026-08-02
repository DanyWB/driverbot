function vehicleEmoji(vehicle = {}) {
  const custom = Array.from(String(vehicle.emoji || "").trim()).slice(0, 4).join("");
  if (custom) return custom;

  const type = vehicle.type || vehicle.vehicle_type;
  if (type === "car") return "🚗";
  if (type === "scooter") return "🛵";
  return "🛵";
}

module.exports = {vehicleEmoji};
