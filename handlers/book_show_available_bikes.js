const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const booking = ctx.session.booking;
  const lang = getCtxLang(ctx);

  if (!booking || !booking.startDate || !booking.endDate || !booking.startTime || !booking.endTime) {
    return ctx.answerCallbackQuery(t(lang, "booking_dates_not_selected"));
  }

  const {startDate, endDate} = booking;
  const {makeDateTime} = require("../utils/timeSlots");
  const {applyOverlapCondition} = require("../utils/overlap");
  const startAt = makeDateTime(startDate, booking.startTime)?.toISOString();
  const endAt = makeDateTime(endDate, booking.endTime)?.toISOString();

  const busyBikes = await db("rentals")
    .select("bike_id")
    .whereNotIn("status", ["cancelled", "cancelled_by_client"])
    .andWhere((builder) => {
      applyOverlapCondition(builder, startAt, endAt, startDate, endDate);
    });

  const busyIds = busyBikes.map((b) => b.bike_id);

  const availableBikes = await db("bikes")
    .select("id", "name")
    .whereNotIn("id", busyIds);

  if (availableBikes.length === 0) {
    // create lead for operator
    try {
      const user = await db("users").where({telegram_id: ctx.from.id}).first();
      const category =
        booking.categoryId &&
        (await db("categories").where({id: booking.categoryId}).first());

      if (user) {
        await db("no_availability_requests").insert({
          user_id: user.id,
          start_date: startDate,
          end_date: endDate,
          category_id: booking.categoryId || null,
        });
        const admin = await db("users").where({is_admin: true}).first();
        if (admin) {
          const catName = category?.name || "-";
          const textAdmin = t(lang, "admin_no_availability_lead", {
            start: dayjs(startDate).format("DD.MM"),
            end: dayjs(endDate).format("DD.MM"),
            user: user.name || t(lang, "user_no_name"),
            username: user.telegram_name || "-",
            category: catName,
            comment: booking.notes || "-",
          });
          await ctx.api.sendMessage(admin.telegram_id, textAdmin);
        }
      }
    } catch (e) {
      // ignore lead errors
    }

    return ctx.editMessageText(t(lang, "booking_no_availability_lead"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "book:restart"}],
          [{text: t(lang, "rent_btn_support"), callback_data: "rent:support"}],
        ],
      },
    });
  }

  const keyboard = availableBikes.map((bike) => [
    {
      text: bike.name,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);

  keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:restart"}]);

  await ctx.editMessageText(t(lang, "booking_available_bikes_title"), {
    reply_markup: {inline_keyboard: keyboard},
  });
};
