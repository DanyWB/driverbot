const {generateCalendarKeyboard} = require("../utils/calendar");
const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {listCategories} = require("../services/laravelGateway");

module.exports = async (ctx) => {
  const action = ctx.callbackQuery?.data || "";
  const [, scenario] = action.split(":");
  const booking = ensureBooking(ctx);

  booking.scenario = scenario;
  booking.step =
    scenario === "date_first" ? "select_start_date" : "select_bike_category";
  booking.selectedBikeId = null;
  booking.startDate = null;
  booking.startTime = null;
  booking.endDate = null;
  booking.endTime = null;
  booking.timeSource = null;
  booking.calendarMonth = dayjs().month() + 1;
  booking.calendarYear = dayjs().year();

  const lang = getCtxLang(ctx);

  if (scenario === "date_first") {
    const keyboard = generateCalendarKeyboard(
      booking.calendarYear,
      booking.calendarMonth,
      [],
      {
        lang,
        labels: getCalendarLabels(lang),
        weekdays: getWeekdays(lang),
        disablePast: true,
      }
    );

    await ctx.editMessageText(
      t(lang, "booking_choose_start_date"),
      {reply_markup: keyboard}
    );
  } else if (scenario === "bike_first") {
    const categories = isLaravelMode() ? await listCategories() : null;
    const categoryLabels = {
      1: "booking_category_light",
      2: "booking_category_comfort",
      3: "booking_category_maxy",
    };
    const categoryButtons = categories
      ? categories.map((category) => [
          {
            text: !isLaravelMode() && categoryLabels[category.id]
              ? t(lang, categoryLabels[category.id])
              : category.name,
            callback_data: `book:cat:${category.id}`,
          },
        ])
      : [
          [{text: t(lang, "booking_category_light"), callback_data: "book:cat:1"}],
          [{text: t(lang, "booking_category_comfort"), callback_data: "book:cat:2"}],
          [{text: t(lang, "booking_category_maxy"), callback_data: "book:cat:3"}],
        ];
    categoryButtons.push([{text: t(lang, "btn_back"), callback_data: "book:start"}]);
    await ctx.editMessageText(t(lang, "booking_choose_category"), {
      reply_markup: {inline_keyboard: categoryButtons},
    });
  }
};
