const {Composer} = require("grammy");
const composer = new Composer();
const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {DEFAULT_BIKE_EMOJI} = require("../utils/constants");

composer.callbackQuery(/^book:cat:(\d+)$/, async (ctx) => {
  const categoryId = Number(ctx.match[1]);
  const lang = getCtxLang(ctx);
  ctx.session.booking = ctx.session.booking || {};
  ctx.session.booking.categoryId = categoryId;

  const booking = ctx.session.booking;
  const backTarget =
    booking.scenario === "date_first" ? "book:show_available_bikes" : "book:start";
  let bikesQuery = db("bikes")
    .select("id", "name", "emoji")
    .where({category_id: categoryId});

  if (booking.startDate && booking.endDate) {
    const {makeDateTime} = require("../utils/timeSlots");
    const {applyOverlapCondition} = require("../utils/overlap");
    const startAt = makeDateTime(booking.startDate, booking.startTime)?.toISOString();
    const endAt = makeDateTime(booking.endDate, booking.endTime)?.toISOString();

    const busyBikes = await db("rentals")
      .select("bike_id")
      .whereNotIn("status", ["cancelled", "cancelled_by_client"])
      .andWhere((builder) => {
        applyOverlapCondition(builder, startAt, endAt, booking.startDate, booking.endDate);
      });

    const busyIds = busyBikes.map((b) => b.bike_id);
    if (busyIds.length) {
      bikesQuery = bikesQuery.whereNotIn("id", busyIds);
    }
  }

  const bikes = await bikesQuery;

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
