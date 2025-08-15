module.exports = async (ctx) => {
  ctx.session.step = "waiting_for_name";
  ctx.session.scenario = null;
  await ctx.reply("👤 Пожалуйста, введите имя:");
};
