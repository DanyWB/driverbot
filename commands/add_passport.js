module.exports = async (ctx) => {
  ctx.session.step = "waiting_for_passport";
  ctx.session.scenario = null;
  await ctx.reply("Пожалуйста, отправьте фото паспорта:");
};
