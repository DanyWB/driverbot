const dayjs = require("dayjs");
const utc = require("dayjs/plugin/utc");
const timezone = require("dayjs/plugin/timezone");
const {t, normalizeLang} = require("./i18n");
dayjs.extend(utc);
dayjs.extend(timezone);

const TZ = process.env.BOOKING_TZ || "Asia/Bangkok";

const REMINDER_TYPES = ["start_24h", "start_1h", "end_24h", "end_1h"];

function buildReminderTimes(rental) {
  const times = [];
  const startAt = rental.start_at
    ? dayjs(rental.start_at)
    : rental.start_date
      ? dayjs.tz(rental.start_date, TZ).hour(8).minute(0).second(0)
      : null;
  const endAt = rental.end_at
    ? dayjs(rental.end_at)
    : rental.end_date
      ? dayjs.tz(rental.end_date, TZ).hour(8).minute(0).second(0)
      : null;

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
  if (!rental || !rental.start_date || !rental.end_date) return;

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
    .leftJoin("bikes", "rentals.bike_id", "bikes.id")
    .where("reminders.sent", false)
    .andWhere("reminders.send_at", "<=", now.toDate())
    .select(
      "rentals.id as rental_id",
      "reminders.id as reminder_id",
      "reminders.type",
      "rentals.start_date",
      "rentals.end_date",
      "rentals.start_at",
      "rentals.end_at",
      "rentals.status",
      "bikes.name as bike_name",
      "users.telegram_id",
      "users.lang"
    );

  for (const row of due) {
    const payload = buildReminderPayload(row);
    if (!payload) continue;
    try {
      await bot.api.sendMessage(row.telegram_id, payload.text, {
        parse_mode: "HTML",
        reply_markup: payload.reply_markup,
      });
      await db("reminders").where({id: row.reminder_id}).update({sent: true});
    } catch (e) {
      // ignore send errors to avoid crash; can be logged
    }
  }
}

function formatDateValue(dateValue, withTime) {
  if (!dateValue) return "-";
  const fmt = withTime ? "DD.MM.YYYY HH:mm" : "DD.MM.YYYY";
  const formatted = dayjs(dateValue).tz(TZ);
  return formatted.isValid() ? formatted.format(fmt) : "-";
}

function buildReminderPayload(row) {
  const lang = normalizeLang(row.lang);
  const hasStartTime = Boolean(row.start_at);
  const hasEndTime = Boolean(row.end_at);
  const start = formatDateValue(row.start_at || row.start_date, hasStartTime);
  const end = formatDateValue(row.end_at || row.end_date, hasEndTime);
  const model = row.bike_name || "-";
  const statusKey = `rent_status_${row.status}`;
  const statusLabel = t(lang, statusKey) || row.status || "-";

  const titleKey = `reminder_${row.type}`;
  const title = t(lang, titleKey);
  if (!title || title === titleKey) return null;

  const text =
    `<b>${title}</b>\n` +
    `${t(lang, "reminder_model_label")}: ${model}\n` +
    `${t(lang, "reminder_period_label")}: ${start} — ${end}\n` +
    `${t(lang, "reminder_status_label")}: ${statusLabel}`;

  return {
    text,
    reply_markup: {
      inline_keyboard: [
        [
          {
            text: t(lang, "reminder_view_details_btn"),
            callback_data: `rent:details:${row.rental_id}`,
          },
        ],
        [{text: t(lang, "reminder_view_current_btn"), callback_data: "rent:current"}],
      ],
    },
  };
}

module.exports = {
  REMINDER_TYPES,
  scheduleRemindersForRental,
  deleteRemindersForRental,
  sendDueReminders,
};
