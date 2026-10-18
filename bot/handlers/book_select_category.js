const {showCategory} = require("./booking_category_list");

module.exports = async ctx => {
  const [, action, categoryId, group] = String(ctx.callbackQuery?.data || "").split(":");
  return showCategory(ctx, Number(categoryId), {group: action === "model" ? group : null});
};
