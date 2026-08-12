const {Composer} = require("grammy");
const composer = new Composer();
const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {DEFAULT_BIKE_EMOJI} = require("../utils/constants");
const {
  listActiveVehicles,
  listAvailableVehicles,
} = require("../services/vehicleService");
const {botScreenRenderer} = require("../services/botScreenRenderer");

composer.callbackQuery(/^book:cat:(\d+)$/, async (ctx) => {
  const categoryId = Number(ctx.match[1]);
  const lang = getCtxLang(ctx);
  ctx.session.booking = ctx.session.booking || {};
  ctx.session.booking.categoryId = categoryId;

  const booking = ctx.session.booking;
  const backTarget =
    booking.scenario === "date_first" ? "book:show_available_bikes" : "book:start";
  let bikes;

  if (booking.startDate && booking.endDate) {
    bikes = await listAvailableVehicles(db, {
      categoryId,
      startDate: booking.startDate,
      endDate: booking.endDate,
      startTime: booking.startTime,
      endTime: booking.endTime,
    });
  } else {
    bikes = await listActiveVehicles(db, {categoryId});
  }

  if (!bikes.length) {
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_bikes_empty",
      text: t(lang, "booking_no_bikes_in_category"),
      replyMarkup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: backTarget}]],
      },
      returnContext: {scenario: booking.scenario, categoryId},
    });
  }

  const buttons = bikes.map((bike) => [
    {
      text: `${bike.emoji || DEFAULT_BIKE_EMOJI} ${bike.name}`,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);
  buttons.push([{text: t(lang, "btn_back"), callback_data: backTarget}]);

  return botScreenRenderer.renderText(ctx, {
    screen: "booking_bikes",
    text: t(lang, "booking_choose_bike"),
    replyMarkup: {inline_keyboard: buttons},
    returnContext: {scenario: booking.scenario, categoryId},
  });
});
module.exports = composer;
