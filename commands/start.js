const db = require("../connect");
const {registerUser} = require("../services/userService");
const {setUserCommands} = require("../utils/setCommands");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;

  let user = await db("users").where({telegram_id: telegramId}).first();
  let isNewUser = false;

  if (!user) {
    await registerUser({
      id: telegramId,
      username: ctx.from.username || null,
    });

    user = await db("users").where({telegram_id: telegramId}).first();
    isNewUser = true;
  }

  await setUserCommands(user, ctx);

  if (isNewUser) {
    await ctx.reply(
      `👋 Добро пожаловать, ${
        ctx.from.first_name || "пользователь"
      }!\n\nЧтобы продолжить, пожалуйста, пройдите небольшую регистрацию.`
    );
  } else {
    await ctx.reply(
      `👋 С возвращением, ${
        user.name || ctx.from.first_name || "пользователь"
      }!`
    );
  }

  const isNameOk = !!user.name;
  const isPhoneOk = !!user.phone;
  const isPassportOk = !!user.passport_photo_file_id;

  if (!isNameOk) {
    ctx.session.step = "waiting_for_name";
    ctx.session.scenario = "registration";
    return ctx.reply("✏️ Пожалуйста, введите ваше имя:");
  }

  if (!isPhoneOk) {
    ctx.session.step = "waiting_for_phone";
    ctx.session.scenario = "registration";
    return ctx.reply(
      "📞 Пожалуйста, введите номер телефона в международном формате (например, +79995551234):"
    );
  }

  if (!isPassportOk) {
    ctx.session.step = "waiting_for_passport";
    ctx.session.scenario = "registration";
    return ctx.reply("🪪 Пожалуйста, отправьте фото паспорта:");
  }

  return ctx.reply("🏠 Главное меню:", {
    reply_markup: {
      inline_keyboard: [
        [{text: "📅 Забронировать байк", callback_data: "book:start"}],
        [{text: "🧾 Моя аренда", callback_data: "book:add_rental"}],
        [
          {text: "✏️ Изменить имя", callback_data: "update:name"},
          {text: "📞 Изменить номер", callback_data: "update:tel"},
        ],
        [{text: "🪪 Загрузить паспорт", callback_data: "update:passport"}],
      ],
    },
  });
};
