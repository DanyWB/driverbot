const dayjs = require("dayjs");
const {t} = require("./i18n");
const {categoryPresentation} = require("./categoryPresentation");
const {preview} = require("./text");

function rentalDate(rental, side) {
  return side === "start"
    ? rental?.start_date || rental?.starts_on || null
    : rental?.end_date || rental?.ends_on || null;
}

function validDate(value) {
  if (!value) return null;
  const parsed = dayjs(value);
  return parsed.isValid() ? parsed : null;
}

function compactPeriod(rental) {
  const start = validDate(rentalDate(rental, "start"));
  const end = validDate(rentalDate(rental, "end"));
  if (!start || !end) return "—";
  const crossYear = start.year() !== end.year();
  const format = crossYear ? "DD.MM.YYYY" : "DD.MM";
  return `${start.format(format)}–${end.format(format)}`;
}

function displayPeriod(rental) {
  const start = validDate(rental?.start_at || rentalDate(rental, "start"));
  const end = validDate(rental?.end_at || rentalDate(rental, "end"));
  const startHasTime = Boolean(rental?.start_at);
  const endHasTime = Boolean(rental?.end_at);
  return {
    start: start ? start.format(startHasTime ? "DD.MM.YYYY HH:mm" : "DD.MM.YYYY") : "—",
    end: end ? end.format(endHasTime ? "DD.MM.YYYY HH:mm" : "DD.MM.YYYY") : "—",
  };
}

function rentalDays(rental) {
  const supplied = Number(rental?.price?.total_days ?? rental?.total_days);
  if (Number.isSafeInteger(supplied) && supplied > 0) return supplied;
  const start = validDate(rentalDate(rental, "start"));
  const end = validDate(rentalDate(rental, "end"));
  if (!start || !end) return null;
  const days = end.startOf("day").diff(start.startOf("day"), "day") + 1;
  return days > 0 ? days : null;
}

function rentalVehicleName(rental) {
  return preview(rental?.bike_name || rental?.vehicle?.name || "—", 36) || "—";
}

function historyButtonText(rental) {
  return preview(`${compactPeriod(rental)} · ${rentalVehicleName(rental)}`, 60);
}

function localizedCategory(rental, lang) {
  const vehicle = rental?.vehicle || {};
  const rawCategory = vehicle.category || {
    code: vehicle.category_code || rental?.category_code || null,
    vehicle_type: vehicle.vehicle_type || vehicle.type || null,
  };
  return categoryPresentation({
    ...rawCategory,
    vehicle_type: rawCategory.vehicle_type || vehicle.type || vehicle.vehicle_type,
  }, lang, {useCustomEmoji: false}).label;
}

function localizedStatus(rental, lang) {
  const status = String(rental?.status || "").trim();
  if (!status) return t(lang, "rent_status_unknown");
  const key = `rent_status_${status}`;
  const label = t(lang, key);
  return label === key ? t(lang, "rent_status_unknown") : label;
}

function createdLabel(rental) {
  const created = validDate(rental?.created_at);
  return created ? created.format("DD.MM.YYYY HH:mm") : null;
}

function cancellationReason(rental) {
  const reason = rental?.cancellation?.reason || rental?.cancellation_reason;
  return String(reason || "").trim() || null;
}

module.exports = {
  cancellationReason,
  compactPeriod,
  createdLabel,
  displayPeriod,
  historyButtonText,
  localizedCategory,
  localizedStatus,
  rentalDate,
  rentalDays,
  rentalVehicleName,
};
