const dayjs = require("dayjs");
const utc = require("dayjs/plugin/utc");
const timezone = require("dayjs/plugin/timezone");
dayjs.extend(utc);
dayjs.extend(timezone);

const TZ = process.env.BOOKING_TZ || "Asia/Bangkok";

const REMINDER_TYPES = ["start_24h", "start_1h", "end_24h", "end_1h"];

function buildReminderTimes(rental) {
  const times = [];
  const startAt = rental.start_at ? dayjs(rental.start_at) : null;
  const endAt = rental.end_at ? dayjs(rental.end_at) : null;

  if (startAt && startAt.isValid()) {
    times.push({type: "start_24h", send_at: startAt.tz(TZ).subtract(24, "hour")});
    times.push({type: "start_1h", send_at: startAt.tz(TZ).subtract(1, "hour")});
  }

  if (endAt && endAt.isValid()) {
    times.push({type: "end_24h", send_at: endAt.tz(TZ).subtract(24, "hour")});
    times.push({type: "end_1h", send_at: endAt.tz(TZ).subtract(1, "hour")});
  }

  return times.filter((t) => t.send_at.isValid());
}

async function scheduleRemindersForRental(db, rentalId) {
  const rental = await db("rentals").where({id: rentalId}).first();
  if (!rental || !rental.start_at || !rental.end_at) return;

  await db("reminders").where({rental_id: rentalId}).del();

  const times = buildReminderTimes(rental);
  if (!times.length) return;

  const rows = times.map((t) => ({
    rental_id: rentalId,
    type: t.type,
    send_at: t.send_at.toDate(),
    sent: false,
  }));

  await db("reminders").insert(rows);
}

async function deleteRemindersForRental(db, rentalId) {
  await db("reminders").where({rental_id: rentalId}).del();
}

async function sendDueReminders(bot, db) {
  const now = dayjs().tz(TZ);
  const due = await db("reminders")
    .join("rentals", "reminders.rental_id", "rentals.id")
    .join("users", "rentals.user_id", "users.id")
    .where("reminders.sent", false)
    .andWhere("reminders.send_at", "<=", now.toDate())
    .select(
      "reminders.id as reminder_id",
      "reminders.type",
      "rentals.start_date",
      "rentals.end_date",
      "rentals.start_at",
      "rentals.end_at",
      "rentals.status",
      "rentals.booking_public_id",
      "users.telegram_id",
      "users.lang"
    );

  for (const row of due) {
    const text = buildReminderText(row);
    if (!text) continue;
    try {
      await bot.api.sendMessage(row.telegram_id, text);
      await db("reminders").where({id: row.reminder_id}).update({sent: true});
    } catch (e) {
      // ignore send errors to avoid crash; can be logged
    }
  }
}

function buildReminderText(row) {
  const lang = (row.lang || "ru").toLowerCase();
  const start = row.start_date
    ? dayjs(row.start_date).tz(TZ).format("DD.MM.YYYY")
    : "-";
  const end = row.end_date ? dayjs(row.end_date).tz(TZ).format("DD.MM.YYYY") : "-";
  const id = row.booking_public_id || row.reminder_id;
  const texts = {
    ru: {
      start_24h: `⏰ Напоминание: аренда стартует через 24 часа\nID: ${id}\n${start} - ${end}`,
      start_1h: `⏰ Напоминание: аренда стартует через 1 час\nID: ${id}\n${start} - ${end}`,
      end_24h: `⏰ Напоминание: аренда завершится через 24 часа\nID: ${id}\n${start} - ${end}`,
      end_1h: `⏰ Напоминание: аренда завершится через 1 час\nID: ${id}\n${start} - ${end}`,
    },
    en: {
      start_24h: `⏰ Reminder: rental starts in 24 hours\nID: ${id}\n${start} - ${end}`,
      start_1h: `⏰ Reminder: rental starts in 1 hour\nID: ${id}\n${start} - ${end}`,
      end_24h: `⏰ Reminder: rental ends in 24 hours\nID: ${id}\n${start} - ${end}`,
      end_1h: `⏰ Reminder: rental ends in 1 hour\nID: ${id}\n${start} - ${end}`,
    },
    ua: {
      start_24h: `⏰ Нагадування: оренда почнеться через 24 години\nID: ${id}\n${start} - ${end}`,
      start_1h: `⏰ Нагадування: оренда почнеться через 1 годину\nID: ${id}\n${start} - ${end}`,
      end_24h: `⏰ Нагадування: оренда завершиться через 24 години\nID: ${id}\n${start} - ${end}`,
      end_1h: `⏰ Нагадування: оренда завершиться через 1 годину\nID: ${id}\n${start} - ${end}`,
    },
  };
  const dict = texts[lang] || texts.ru;
  switch (row.type) {
    case "start_24h":
      return dict.start_24h;
    case "start_1h":
      return dict.start_1h;
    case "end_24h":
      return dict.end_24h;
    case "end_1h":
      return dict.end_1h;
    default:
      return null;
  }
}

module.exports = {
  REMINDER_TYPES,
  scheduleRemindersForRental,
  deleteRemindersForRental,
  sendDueReminders,
};
