const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml} = require("../utils/html");
const {ADMIN_LIST_STATUSES} = require("../utils/rentalStatus");

function getAdminMenuKeyboard(lang) {
  return {
    inline_keyboard: [
      [
        {
          text: t(lang, "admin_menu_active_btn"),
          callback_data: "admin:list:active",
        },
      ],
      [
        {
          text: t(lang, "admin_menu_pending_btn"),
          callback_data: "admin:list:pending",
        },
      ],
      [
        {
          text: t(lang, "admin_menu_confirmed_btn"),
          callback_data: "admin:list:confirmed",
        },
      ],
      [
        {
          text: t(lang, "admin_menu_bikes_btn"),
          callback_data: "admin:bikes",
        },
      ],
    ],
  };
}

async function sendAdminMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  return ctx.reply(t(lang, "admin_menu_title"), {
    reply_markup: getAdminMenuKeyboard(lang),
  });
}

async function safeAnswer(ctx, text, showAlert = false) {
  try {
    if (text) {
      await ctx.answerCallbackQuery({text, show_alert: showAlert});
      return;
    }
    await ctx.answerCallbackQuery();
  } catch (e) {
    // ignore expired or invalid queries
  }
}

async function ensureAdmin(ctx, lang) {
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user || !user.is_admin) {
    await safeAnswer(ctx, t(lang, "admin_not_allowed"), true);
    return null;
  }
  return user;
}

function formatRentalDates(rental) {
  const start = rental.start_at || rental.start_date;
  const end = rental.end_at || rental.end_date;
  const startLabel = start ? dayjs(start).format("DD.MM.YYYY HH:mm") : "-";
  const endLabel = end ? dayjs(end).format("DD.MM.YYYY HH:mm") : "-";
  return {startLabel, endLabel};
}

async function handleAdminAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data || "";

  const isAdmin = await ensureAdmin(ctx, lang);
  if (!isAdmin) return;

  if (action === "admin:menu") {
    await safeAnswer(ctx);
    return ctx.editMessageText(t(lang, "admin_menu_title"), {
      reply_markup: getAdminMenuKeyboard(lang),
    });
  }

  const listMatch = action.match(/^admin:list:(active|pending|confirmed)$/);
  if (listMatch) {
    await safeAnswer(ctx);
    const type = listMatch[1];
    const statuses = ADMIN_LIST_STATUSES[type] || [];

    const rentals = await db("rentals")
      .leftJoin("users", "rentals.user_id", "users.id")
      .leftJoin("bikes", "rentals.bike_id", "bikes.id")
      .whereIn("rentals.status", statuses)
      .select(
        "rentals.*",
        "users.name as user_name",
        "users.telegram_name as username",
        "users.phone as phone",
        "bikes.name as bike_name"
      )
      .orderBy("rentals.created_at", "desc");

    const titleKey = `admin_list_title_${type}`;
    if (!rentals.length) {
      return ctx.editMessageText(t(lang, "admin_list_empty"), {
        reply_markup: {
          inline_keyboard: [
            [{text: t(lang, "btn_back"), callback_data: "admin:menu"}],
          ],
        },
      });
    }

    let text = `<b>${t(lang, titleKey)}</b>\n\n`;
    const keyboard = [];

    rentals.forEach((rental) => {
      const idLabel = rental.booking_public_id || rental.id;
      const statusLabel = t(lang, `rent_status_${rental.status}`) || rental.status;
      const {startLabel, endLabel} = formatRentalDates(rental);
      const userName = escapeHtml(rental.user_name || t(lang, "user_no_name"));
      const username = rental.username ? `@${escapeHtml(rental.username)}` : "-";
      const phone = escapeHtml(rental.phone || "-");
      const bikeName = escapeHtml(rental.bike_name || "-");

      text += `#${idLabel} • ${bikeName}\n`;
      text += `${t(lang, "admin_list_client")}: ${userName} (${username})\n`;
      text += `${t(lang, "admin_list_phone")}: ${phone}\n`;
      text += `${t(lang, "admin_list_dates")}: ${startLabel} - ${endLabel}\n`;
      text += `${t(lang, "admin_list_status")}: ${statusLabel}\n\n`;

      keyboard.push([
        {
          text: t(lang, "admin_deposit_btn"),
          callback_data: `admin:deposit:${rental.id}`,
        },
      ]);
    });

    keyboard.push([{text: t(lang, "btn_back"), callback_data: "admin:menu"}]);

    return ctx.editMessageText(text, {
      parse_mode: "HTML",
      reply_markup: {inline_keyboard: keyboard},
    });
  }

  const depositMatch = action.match(/^admin:deposit:(\d+)$/);
  if (depositMatch) {
    await safeAnswer(ctx);
    const rentalId = Number(depositMatch[1]);
    const rental = await db("rentals").where({id: rentalId}).first();
    if (!rental) {
      return ctx.reply(t(lang, "admin_rental_not_found"));
    }

    ctx.session.step = "admin_deposit_note";
    ctx.session.adminDepositRentalId = rentalId;
    return ctx.reply(
      t(lang, "admin_deposit_prompt", {
        id: rental.booking_public_id || rental.id,
      })
    );
  }
}

module.exports = {sendAdminMenu, handleAdminAction};
