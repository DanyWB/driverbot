const MODEL_GROUPS = ["aerox", "nmax", "pcx", "adv", "other"];

function modelGroup(vehicle) {
  const name = String(vehicle?.name || "");
  if (/\baerox\b/i.test(name)) return "aerox";
  if (/\bn[\s-]*max\b/i.test(name)) return "nmax";
  if (/\bpcx(?=\b|\d)/i.test(name)) return "pcx";
  if (/\badv(?=\b|\d)/i.test(name)) return "adv";
  return "other";
}

function availableModelGroups(vehicles) {
  const present = new Set(vehicles.map(modelGroup));
  return MODEL_GROUPS.filter(group => present.has(group));
}

module.exports = {MODEL_GROUPS, modelGroup, availableModelGroups};
