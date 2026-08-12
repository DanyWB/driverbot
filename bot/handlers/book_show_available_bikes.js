const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const {listAvailableVehicles} = require("../services/vehicleService");
const {isLaravelMode} = require("../config/runtime");
const {listCategories} = require("../services/laravelGateway");
const {vehicleEmoji} = require("../utils/vehicle");
const {renderCategoryRowsWithFallback} = require("../utils/categoryPresentation");
const {botScreenRenderer} = require("../services/botScreenRenderer");

module.exports = async (ctx, options = {}) => {
  const booking = ctx.session.booking;
  const lang = getCtxLang(ctx);
  const renderer = options.renderer || botScreenRenderer;

  if (!booking || !booking.startDate || !booking.endDate) {
    return ctx.answerCallbackQuery(t(lang, "booking_dates_not_selected"));
  }

  if (booking.selectedBikeId) {
    const {finalizeBikeSelection} = require("./book_select_bike");
    return finalizeBikeSelection(ctx, booking, booking.selectedBikeId, lang, {
      renderer,
    });
  }

  const {startDate, endDate} = booking;
  const availableBikes = await listAvailableVehicles(db, {
    startDate,
    endDate,
    startTime: booking.startTime,
    endTime: booking.endTime,
    categoryId:
      options.preserveCategory && booking.categoryId
        ? booking.categoryId
        : null,
  });

  if (availableBikes.length === 0) {
    // create lead for operator
    if (!isLaravelMode()) try {
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
          const textAdmin = tHtml(lang, "admin_no_availability_lead", {
            start: dayjs(startDate).format("DD.MM.YYYY"),
            end: dayjs(endDate).format("DD.MM.YYYY"),
            user: user.name || t(lang, "user_no_name"),
            username: user.telegram_name || "-",
            phone: user.phone || "-",
            category: catName,
            comment: booking.notes || "-",
          });
          await ctx.api.sendMessage(admin.telegram_id, textAdmin, {
            parse_mode: "HTML",
          });
        }
      }
    } catch (e) {
      // ignore lead errors
    }

    const messageKey = isLaravelMode()
      ? "booking_no_available_bikes"
      : "booking_no_availability_lead";
    return renderer.renderText(ctx, {
      screen: "booking_no_availability",
      text: t(lang, messageKey),
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "book:restart"}],
          [{text: t(lang, "rent_btn_support"), callback_data: "rent:support"}],
        ],
      },
      returnContext: {scenario: booking.scenario},
      navigationMode: "replace",
    });
  }

  if (booking.scenario === "date_first" && !options.preserveCategory) {
    booking.categoryId = null;
    const categoryIds = [
      ...new Set(availableBikes.map((bike) => bike.category_id).filter(Boolean)),
    ];
    const categories = isLaravelMode()
      ? (await listCategories()).filter((category) => categoryIds.includes(category.id))
      : await db("categories")
          .select("id", "name")
          .whereIn("id", categoryIds)
          .orderBy("id");

    if (categories.length) {
      if (isLaravelMode()) {
        return renderCategoryRowsWithFallback(
          ctx,
          categories,
          lang,
          (category) => `book:cat:${category.id}`,
          (keyboard) => {
            keyboard.push([
              {text: t(lang, "btn_back"), callback_data: "book:restart"},
            ]);
            return renderer.renderText(ctx, {
              screen: "booking_category",
              text: t(lang, "booking_choose_category"),
              replyMarkup: {inline_keyboard: keyboard},
              returnContext: {
                scenario: booking.scenario,
                startDate,
                endDate,
              },
            });
          }
        );
      }

      const categoryLabels = {
        1: "booking_category_light",
        2: "booking_category_comfort",
        3: "booking_category_maxy",
      };
      const keyboard = categories.map((category) => [{
        text: categoryLabels[category.id]
          ? t(lang, categoryLabels[category.id])
          : category.name,
        callback_data: `book:cat:${category.id}`,
      }]);
      keyboard.push([{text: t(lang, "btn_back"), callback_data: "book:restart"}]);
      return renderer.renderText(ctx, {
        screen: "booking_category",
        text: t(lang, "booking_choose_category"),
        replyMarkup: {inline_keyboard: keyboard},
        returnContext: {
          scenario: booking.scenario,
          startDate,
          endDate,
        },
      });
    }
  }

  const keyboard = availableBikes.map((bike) => [
    {
      text: `${vehicleEmoji(bike)} ${bike.name}`,
      callback_data: `book:select_bike:${bike.id}`,
    },
  ]);

  const backAction = options.preserveCategory && booking.scenario === "date_first"
    ? "book:show_available_bikes"
    : "book:restart";
  keyboard.push([{text: t(lang, "btn_back"), callback_data: backAction}]);

  return renderer.renderText(ctx, {
    screen: "booking_bikes",
    text: t(lang, "booking_available_bikes_title"),
    replyMarkup: {inline_keyboard: keyboard},
    returnContext: {
      scenario: booking.scenario,
      categoryId: booking.categoryId || null,
      startDate,
      endDate,
    },
  });
};
