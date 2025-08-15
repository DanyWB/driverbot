const addNameCommand = require("../commands/add_name");
const addTelCommand = require("../commands/add_tel");
const addPassportCommand = require("../commands/add_passport");

const GO_ACTIONS = {
  go_add_name: addNameCommand,
  go_add_tel: addTelCommand,
  go_add_passport: addPassportCommand,
};

module.exports = async (ctx, next) => {
  const action = ctx.callbackQuery?.data;

  if (action === "start_registration") {
    ctx.session.step = null;
    ctx.session.scenario = "registration";
    await ctx.answerCallbackQuery();
    await ctx.reply("Начнём с имени.");
    return addNameCommand(ctx);
  }

  if (action === "skip_registration") {
    await ctx.answerCallbackQuery("Регистрацию можно пройти позже.");
    return ctx.reply("Хорошо, если передумаете — введите /start снова.");
  }

  // Универсальная обработка go_*
  if (GO_ACTIONS[action]) {
    ctx.session.step = null;
    ctx.session.scenario = null;
    await ctx.answerCallbackQuery();
    return GO_ACTIONS[action](ctx);
  }

  await next();
};
