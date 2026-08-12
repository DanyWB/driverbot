const {
  generateCalendarKeyboard,
  getCalendarBackAction,
} = require("../utils/calendar");
const dayjs = require("dayjs");
const {ensureBooking} = require("../services/bookingService");
const {t, getCtxLang, getCalendarLabels, getWeekdays} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {listCategories} = require("../services/laravelGateway");
const {renderCategoryRowsWithFallback} = require("../utils/categoryPresentation");
const {botScreenRenderer} = require("../services/botScreenRenderer");

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
        backAction: getCalendarBackAction(booking),
      }
    );

    return botScreenRenderer.renderText(ctx, {
      screen: "booking_start_date",
      text: t(lang, "booking_choose_start_date"),
      replyMarkup: keyboard,
      returnContext: {scenario, categoryId: null, selectedBikeId: null},
    });
  } else if (scenario === "bike_first") {
    const categories = isLaravelMode() ? await listCategories() : null;
    if (categories) {
      return renderCategoryRowsWithFallback(
        ctx,
        categories,
        lang,
        (category) => `book:cat:${category.id}`,
        (categoryButtons) => {
          categoryButtons.push([
            {text: t(lang, "btn_back"), callback_data: "book:start"},
          ]);
          return botScreenRenderer.renderText(ctx, {
            screen: "booking_category",
            text: t(lang, "booking_choose_category"),
            replyMarkup: {inline_keyboard: categoryButtons},
            returnContext: {scenario},
          });
        }
      );
    }

    const categoryButtons = [
          [{text: t(lang, "booking_category_light"), callback_data: "book:cat:1"}],
          [{text: t(lang, "booking_category_comfort"), callback_data: "book:cat:2"}],
          [{text: t(lang, "booking_category_maxy"), callback_data: "book:cat:3"}],
    ];
    categoryButtons.push([{text: t(lang, "btn_back"), callback_data: "book:start"}]);
    return botScreenRenderer.renderText(ctx, {
      screen: "booking_category",
      text: t(lang, "booking_choose_category"),
      replyMarkup: {inline_keyboard: categoryButtons},
      returnContext: {scenario},
    });
  }
};
