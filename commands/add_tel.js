module.exports = async (ctx) => {
  ctx.session.step = "waiting_for_phone";
  ctx.session.scenario = null;
  await ctx.reply(
    "Пожалуйста, введите номер телефона в международном формате (например, +79995551234):"
  );
};
