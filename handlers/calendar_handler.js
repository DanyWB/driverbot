const {generateCalendarKeyboard} = require("../utils/calendar");
const dayjs = require("dayjs");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data; // e.g. 'calendar:2025-07-06'
  const date = data.split(":")[1];
  const lang = getCtxLang(ctx);

  if (!ctx.session.booking) {
    return ctx.answerCallbackQuery(t(lang, "calendar_no_scenario"));
  }

  const {booking} = ctx.session;

  if (!booking.startDate) {
    booking.startDate = date;

    await ctx.editMessageText(
      t(lang, "booking_start_date_selected", {
        date: dayjs(date).format("DD.MM.YYYY"),
      }),
      {
        reply_markup: generateCalendarKeyboard(
          dayjs(date).year(),
          dayjs(date).month() + 1,
          [],
          {lang, labels: getCalendarLabels(lang), weekdays: getWeekdays(lang)}
        ),
      }
    );
    return;
  }

  if (!booking.endDate) {
    const start = dayjs(booking.startDate);
    const end = dayjs(date);

    if (end.isBefore(start)) {
      return ctx.answerCallbackQuery(t(lang, "booking_end_before_start"));
    }

    booking.endDate = date;

    await ctx.editMessageText(
      t(lang, "booking_period_selected", {
        start: start.format("DD.MM.YYYY"),
        end: end.format("DD.MM.YYYY"),
      }),
      {
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: t(lang, "booking_choose_bike_btn"),
                callback_data: "book:show_available_bikes",
              },
            ],
            [{text: t(lang, "btn_back"), callback_data: "book:restart"}],
          ],
        },
      }
    );
  }
};
