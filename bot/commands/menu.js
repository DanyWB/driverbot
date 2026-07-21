// Shows the main menu from any state.
module.exports = async (ctx) => {
  // Reset step/scenario so the user can freely navigate.
  if (ctx.session) {
    ctx.session.step = null;
    ctx.session.scenario = null;
  }
  return require("./start")(ctx);
};
