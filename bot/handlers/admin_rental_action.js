const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {tHtml} = require("../utils/html");
const {approveRental, cancelRentalByAdmin} = require("../services/rentalService");
const {
  ADMIN_CANCELLABLE_RENTAL_STATUSES,
} = require("../utils/rentalStatus");

async function safeAnswer(ctx, text, showAlert = false) {
  if (!ctx.callbackQuery) return;
  try {
    if (text) {
      await ctx.answerCallbackQuery({text, show_alert: showAlert});
      return;
    }
    await ctx.answerCallbackQuery();
  } catch (e) {
    // ignore expired or invalid callback queries
  }
}

async function ensureAdmin(ctx, lang) {
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user || !user.is_admin) {
    await safeAnswer(ctx, t(lang, "admin_not_allowed"), true);
    if (!ctx.callbackQuery) {
      await ctx.reply(t(lang, "admin_not_allowed"));
    }
    return null;
  }
  return user;
}

async function notifyUserApproval(ctx, rental) {
  if (!rental.user_id) return;
  const user = await db("users").where({id: rental.user_id}).first();
  if (!user || !user.telegram_id) return;

  const bike = rental.bike_id
    ? await db("bikes").where({id: rental.bike_id}).first()
    : null;
  const userLang = user.lang || "ru";
  const start = rental.start_at || rental.start_date;
  const end = rental.end_at || rental.end_date;
  const startLabel = start ? dayjs(start).format("DD.MM.YYYY HH:mm") : "-";
  const endLabel = end ? dayjs(end).format("DD.MM.YYYY HH:mm") : "-";
  const statusLabel = t(userLang, "rent_status_approved") || "approved";
  const text = tHtml(userLang, "user_rental_approved", {
    id: rental.booking_public_id || rental.id,
    start: startLabel,
    end: endLabel,
    model: bike?.name || "",
    status: statusLabel,
  });

  await ctx.api.sendMessage(user.telegram_id, text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [
          {
            text: t(userLang, "rent_view_details_btn"),
            callback_data: `rent:details:${rental.id}`,
          },
        ],
        [{text: t(userLang, "btn_main_menu"), callback_data: "home"}],
      ],
    },
  });
}

async function notifyUserDecline(ctx, rental, reason) {
  if (!rental.user_id) return;
  const user = await db("users").where({id: rental.user_id}).first();
  if (!user || !user.telegram_id) return;
  const userLang = user.lang || "ru";
  const reasonText =
    reason && reason.trim()
      ? reason.trim()
      : t(userLang, "user_decline_reason_default");
  const text = tHtml(userLang, "user_rental_declined", {
    id: rental.booking_public_id || rental.id,
    reason: reasonText,
  });

  await ctx.api.sendMessage(user.telegram_id, text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [{text: t(userLang, "btn_main_menu"), callback_data: "home"}],
        [{text: t(userLang, "rent_btn_support"), callback_data: "rent:support"}],
      ],
    },
  });
}

async function finalizeDecline(ctx, rentalId, reason) {
  const lang = getCtxLang(ctx);
  const admin = await ensureAdmin(ctx, lang);
  if (!admin) return;

  const result = await cancelRentalByAdmin(db, {rentalId});
  if (result.status === "not_found") {
    await ctx.reply(t(lang, "admin_rental_not_found"));
    return;
  }
  if (result.status !== "cancelled") {
    await ctx.reply(t(lang, "admin_rental_status_changed"));
    return;
  }

  const rental = result.rental;
  await notifyUserDecline(ctx, rental, reason);

  const reasonText =
    reason && reason.trim()
      ? reason.trim()
      : t(lang, "user_decline_reason_default");
  await ctx.reply(
    t(lang, "admin_decline_done", {
      id: rental.booking_public_id || rental.id,
      reason: reasonText,
    })
  );
}

module.exports = async (ctx) => {
  const data = ctx.callbackQuery.data; // e.g. admin:rental:approve:17
  const [_, __, action, rentalIdRaw] = data.split(":");
  const rentalId = Number(rentalIdRaw);
  const lang = getCtxLang(ctx);

  const admin = await ensureAdmin(ctx, lang);
  if (!admin) return;

  const rental = await db("rentals").where({id: rentalId}).first();
  if (!rental) return ctx.reply(t(lang, "admin_rental_not_found"));

  if (action === "cancel") {
    await safeAnswer(ctx);
    if (!ADMIN_CANCELLABLE_RENTAL_STATUSES.includes(rental.status)) {
      return ctx.reply(t(lang, "admin_rental_status_changed"));
    }
    ctx.session.step = "admin_decline_reason";
    ctx.session.adminDeclineRentalId = rentalId;
    await ctx.editMessageReplyMarkup({reply_markup: {inline_keyboard: []}});
    return ctx.reply(
      t(lang, "admin_decline_prompt", {id: rental.booking_public_id || rental.id}),
      {
        reply_markup: {
          inline_keyboard: [
            [
              {
                text: t(lang, "admin_decline_skip_btn"),
                callback_data: `admin:rental:cancel_skip:${rentalId}`,
              },
            ],
          ],
        },
      }
    );
  }

  if (action === "cancel_skip") {
    await safeAnswer(ctx);
    ctx.session.step = null;
    ctx.session.adminDeclineRentalId = null;
    await ctx.editMessageReplyMarkup({reply_markup: {inline_keyboard: []}});
    return finalizeDecline(ctx, rentalId, "");
  }

  if (action === "approve") {
    await safeAnswer(ctx);

    let result;
    try {
      result = await approveRental(db, {rentalId});
    } catch (error) {
      if (error.code === "overlap_conflict") {
        return ctx.reply(t(lang, "booking_bike_busy", {name: ""}));
      }
      throw error;
    }

    if (result.status === "not_found") {
      return ctx.reply(t(lang, "admin_rental_not_found"));
    }
    if (result.status !== "approved") {
      return ctx.reply(t(lang, "admin_rental_status_changed"));
    }

    await ctx.editMessageReplyMarkup({reply_markup: {inline_keyboard: []}});
    await ctx.editMessageText(
      t(lang, "admin_request_status", {
        id: rental.booking_public_id || rentalId,
        status: t(lang, "admin_status_approved"),
      })
    );

    await notifyUserApproval(ctx, result.rental);
  }
};

module.exports.finalizeDecline = finalizeDecline;
