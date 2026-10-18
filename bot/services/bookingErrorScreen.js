const {t, getCtxLang} = require("../utils/i18n");
const {botScreenRenderer} = require("./botScreenRenderer");

async function showBookingValidationError(ctx, error, backAction = "book:draft") {
  const fields = Object.keys(error.fields || {});
  const dateError = error.code === "BOOKING_REQUEST_EXPIRED" || (error.code === "VALIDATION_FAILED" && fields.some(field =>
    /(^|\.)(starts_on|ends_on)$/.test(field)));
  const minimumError = error.code === "MINIMUM_RENTAL_DAYS";
  if (!dateError && !minimumError) return false;
  const lang = getCtxLang(ctx);
  await botScreenRenderer.renderText(ctx, {
    screen: "booking_dates_invalid",
    text: t(lang, minimumError ? "booking_minimum_days_error" : "booking_dates_invalid", {
      days: error.details?.minimum_days || "",
    }),
    replyMarkup: {inline_keyboard: [
      [{text: t(lang, "btn_back"), callback_data: backAction}],
      [{text: t(lang, "booking_reset_btn"), callback_data: "book:reset_rental"}],
    ]},
    navigationMode: "replace",
  });
  return true;
}

module.exports = {showBookingValidationError};
