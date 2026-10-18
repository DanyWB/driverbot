const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {vehicleEmoji} = require("../utils/vehicle");
const {isLaravelMode} = require("../config/runtime");
const gateway = require("../services/laravelGateway");
const vehicles = require("../services/vehicleService");
const {botScreenRenderer} = require("../services/botScreenRenderer");
const {MODEL_GROUPS, modelGroup, availableModelGroups} = require("../utils/modelGroups");

async function showCategory(ctx, categoryId, options = {}) {
  const booking = ctx.session.booking ||= {};
  const lang = getCtxLang(ctx);
  const renderer = options.renderer || botScreenRenderer;
  booking.categoryId = Number(categoryId);
  if (!options.preserveGroup) booking.modelGroup = null;
  if (options.group && MODEL_GROUPS.includes(options.group)) booking.modelGroup = options.group;
  const bikes = options.bikes || (booking.startDate && booking.endDate
    ? await vehicles.listAvailableVehicles(db, {...booking, categoryId})
    : await vehicles.listActiveVehicles(db, {categoryId}));
  const categories = options.categories || (isLaravelMode() ? await gateway.listCategories() : []);
  const category = categories.find(item => Number(item.id) === Number(categoryId));
  const grouped = category?.code === "comfort-scooters";
  const back = booking.scenario === "date_first" ? "book:show_available_bikes" : "book:start";
  const group = grouped ? booking.modelGroup : null;
  const filtered = group ? bikes.filter(bike => modelGroup(bike) === group) : bikes;
  const rows = grouped && !group && bikes.length
    ? availableModelGroups(bikes).map(key => [{
      text: key === "other" ? t(lang, "booking_model_other") : ({aerox: "Aerox", nmax: "NMAX", pcx: "PCX", adv: "ADV"})[key],
      callback_data: `book:model:${categoryId}:${key}`,
    }])
    : filtered.map(bike => [{text: `${vehicleEmoji(bike)} ${bike.name}`, callback_data: `book:select_bike:${bike.id}`}]);
  rows.push([{text: t(lang, "btn_back"), callback_data: group ? `book:cat:${categoryId}` : back}]);
  return renderer.renderText(ctx, {
    screen: grouped && !group ? "booking_models" : "booking_bikes",
    text: !filtered.length ? t(lang, "booking_no_bikes_in_category") : t(lang, grouped && !group ? "booking_choose_model" : "booking_choose_bike"),
    replyMarkup: {inline_keyboard: rows},
    returnContext: {scenario: booking.scenario, categoryId, modelGroup: group},
    navigationMode: options.navigationMode || "push",
  });
}

module.exports = {showCategory};
