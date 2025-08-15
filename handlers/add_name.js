const {updateUserName} = require("../services/userService");
const nameRegex = /^[a-zA-Zа-яА-ЯёЁ\s\-]{2,}$/;

module.exports = async (ctx) => {
  const name = ctx.message?.text?.trim();
  const fromId = ctx.from.id;

  if (!nameRegex.test(name)) {
    return ctx.reply(
      "⚠️ Имя должно содержать только буквы и быть не короче 2 символов. Попробуйте снова."
    );
  }

  const success = await updateUserName(fromId, name);
  if (!success) {
    return ctx.reply("❌ Не удалось сохранить имя. Попробуйте снова.");
  }

  await ctx.reply("✅ Имя успешно сохранено.");

  if (ctx.session?.scenario === "registration") {
    ctx.session.step = "waiting_for_phone";
    return ctx.reply(
      "📞 Пожалуйста, введите номер телефона в международном формате (например, +79995551234):"
    );
  }

  ctx.session.step = null;
  ctx.session.scenario = null;
  return require("../commands/start")(ctx);
};
