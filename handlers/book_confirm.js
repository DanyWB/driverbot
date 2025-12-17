const db = require("../connect");
const dayjs = require("dayjs");

module.exports = async (ctx) => {
  const telegramId = ctx.from.id;

  const user = await db("users").where({telegram_id: telegramId}).first();
  if (!user) {
    return ctx.reply("Вы не зарегистрированы.");
  }

  const rentals = await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process");

  if (rentals.length === 0) {
    return ctx.answerCallbackQuery("Нет бронирований для подтверждения.");
  }

  for (const rental of rentals) {
    const overlapping = await db("rentals")
      .where("bike_id", rental.bike_id)
      .andWhere("status", "!=", "cancelled")
      .andWhere(function () {
        this.whereBetween("start_date", [rental.start_date, rental.end_date])
          .orWhereBetween("end_date", [rental.start_date, rental.end_date])
          .orWhere(function () {
            this.where("start_date", "<=", rental.start_date).andWhere(
              "end_date",
              ">=",
              rental.end_date
            );
          });
      })
      .andWhere(function () {
        this.whereNot(function () {
          this.where("status", "process").andWhere("user_id", user.id);
        });
      });

    const bike = await db("bikes").where("id", rental.bike_id).first();
    if (overlapping.length > 0) {
      return ctx.reply(
        `Байк "${bike.name}" уже занят на выбранные даты. Проверьте новый период.`
      );
    }
  }

  await db("rentals")
    .where("user_id", user.id)
    .andWhere("status", "process")
    .update({
      status: "active",
      confirmed_at: dayjs().toISOString(),
    });

  await ctx.editMessageText(
    "✅ Ваша аренда подтверждена!\nОжидайте подтверждения администратора.",
    {
      reply_markup: {
        inline_keyboard: [[{text: "🏠 В меню", callback_data: "home"}]],
      },
    }
  );

  for (const rental of rentals) {
    const bike = await db("bikes").where({id: rental.bike_id}).first();
    const admin = await db("users").where({is_admin: true}).first();
    if (!admin) {
      await ctx.reply(
        "Извините. Сейчас в системе нет администратора. Пожалуйста, отмените эту аренду и повторите попытку позднее."
      );
      return;
    }
    const text = `🚨 <b>Новая аренда</b>\n\nПользователь: ${
      user.name || "(без имени)"
    } Telegram: @${user.telegram_name || "-"}\nБайк: <b>${bike.name}</b>\nСрок: ${dayjs(
      rental.start_date
    ).format("DD.MM")} - ${dayjs(rental.end_date).format(
      "DD.MM"
    )}\nИтог: ${rental.total_price} THB\nКомментарий: ${
      rental.comment || "-"
    }`;

    await ctx.api.sendMessage(admin.telegram_id, text, {
      parse_mode: "HTML",
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: "Подтвердить",
              callback_data: `admin:rental:approve:${rental.id}`,
            },
            {
              text: "Отклонить",
              callback_data: `admin:rental:cancel:${rental.id}`,
            },
          ],
        ],
      },
    });
  }
};
