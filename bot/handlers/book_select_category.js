const {Composer} = require("grammy");
const composer = new Composer();
const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {DEFAULT_BIKE_EMOJI} = require("../utils/constants");
const {
  listActiveVehicles,
  listAvailableVehicles,
} = require("../services/vehicleService");

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
    return ctx.editMessageText(t(lang, "booking_no_bikes_in_category"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: backTarget}]],
      },
    });
  }

  const buttons = bikes.map((bike) => [
    {
      text: `${bike.emoji || DEFAULT_BIKE_EMOJI} ${bike.name}`,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);
  buttons.push([{text: t(lang, "btn_back"), callback_data: backTarget}]);

  await ctx.editMessageText(t(lang, "booking_choose_bike"), {
    reply_markup: {inline_keyboard: buttons},
  });
});
module.exports = composer;
