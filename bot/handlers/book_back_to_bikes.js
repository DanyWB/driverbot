const showAvailableBikes = require("./book_show_available_bikes");

module.exports = async (ctx) => {
  if (ctx.session?.booking) {
    ctx.session.booking.selectedBikeId = null;
  }

  if (ctx.session?.booking?.categoryId) {
    return require("./booking_category_list").showCategory(ctx, ctx.session.booking.categoryId, {
      preserveGroup: true, navigationMode: "back",
    });
  }
  return showAvailableBikes(ctx, {preserveCategory: true});
};
