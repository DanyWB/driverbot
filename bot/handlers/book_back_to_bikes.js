const showAvailableBikes = require("./book_show_available_bikes");

module.exports = async (ctx) => {
  if (ctx.session?.booking) {
    ctx.session.booking.selectedBikeId = null;
  }

  return showAvailableBikes(ctx, {preserveCategory: true});
};
