const VEHICLE_TYPE = Object.freeze({
  SCOOTER: "scooter",
  CAR: "car",
});

const KNOWN_VEHICLE_TYPES = Object.values(VEHICLE_TYPE);
const DEFAULT_VEHICLE_TYPE = VEHICLE_TYPE.SCOOTER;

function normalizeVehicleType(type) {
  return KNOWN_VEHICLE_TYPES.includes(type) ? type : DEFAULT_VEHICLE_TYPE;
}

module.exports = {
  VEHICLE_TYPE,
  KNOWN_VEHICLE_TYPES,
  DEFAULT_VEHICLE_TYPE,
  normalizeVehicleType,
};
