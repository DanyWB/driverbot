const db = require("../connect");
const dayjs = require("dayjs");
const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml, tHtml} = require("../utils/html");
const {sendSupportMenu} = require("./support");
const {sendAccountMenu} = require("./account_menu");
const {
  CURRENT_RENTAL_STATUSES,
  HISTORY_RENTAL_STATUSES,
  USER_CANCELLABLE_RENTAL_STATUSES,
} = require("../utils/rentalStatus");

function getRentMenuKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}],
      [
        {text: t(lang, "rent_btn_current"), callback_data: "rent:current"},
        {text: t(lang, "rent_btn_history"), callback_data: "rent:history"},
      ],
      [
        {text: t(lang, "rent_btn_contract"), callback_data: "rent:contract"},
        {text: t(lang, "rent_btn_payment"), callback_data: "rent:payment"},
      ],
      [
        {text: t(lang, "rent_btn_settings"), callback_data: "rent:settings"},
        {text: t(lang, "rent_btn_support"), callback_data: "rent:support"},
      ],
      [{text: t(lang, "btn_main_menu"), callback_data: "home"}],
    ],
  };
}

async function sendRentMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  return ctx.reply(t(lang, "rent_intro"), {
    parse_mode: "HTML",
    reply_markup: getRentMenuKeyboard(lang),
  });
}

async function sendDepositInfo(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) return ctx.reply(t(lang, "not_registered"));

  const rentals = await db("rentals")
    .leftJoin("bikes", "rentals.bike_id", "bikes.id")
    .where("rentals.user_id", user.id)
    .whereIn("rentals.status", CURRENT_RENTAL_STATUSES)
    .select("rentals.*", "bikes.name as bike_name")
    .orderBy("rentals.created_at", "desc");

  if (!rentals.length) {
    return ctx.editMessageText(t(lang, "rent_deposit_empty"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "rent:menu"}],
        ],
      },
    });
  }

  let text = `<b>${t(lang, "rent_deposit_title")}</b>\n\n`;
  rentals.forEach((rental) => {
    const startLabel = rental.start_at
      ? dayjs(rental.start_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.start_date).format("DD.MM.YYYY");
    const endLabel = rental.end_at
      ? dayjs(rental.end_at).format("DD.MM.YYYY HH:mm")
      : dayjs(rental.end_date).format("DD.MM.YYYY");
    const statusLabel = rental.deposit_paid
      ? t(lang, "rent_deposit_status_paid")
      : t(lang, "rent_deposit_status_unpaid");
    const depositValue =
      rental.deposit_required != null ? rental.deposit_required : "-";
    text += `<b>${escapeHtml(rental.bike_name || "-")}</b>\n`;
    text += `ID: ${escapeHtml(rental.booking_public_id || rental.id)}\n`;
    text += `${tHtml(lang, "rent_details_dates", {
      start: startLabel,
      end: endLabel,
    })}\n`;
    text += `${t(lang, "rent_deposit_required_label")}: ${escapeHtml(depositValue)} THB\n`;
    text += `${t(lang, "rent_deposit_status_label")}: ${escapeHtml(statusLabel)}\n`;
    if (rental.deposit_note) {
      text += `${t(lang, "rent_deposit_note_label")}: ${escapeHtml(rental.deposit_note)}\n`;
    }
    text += "\n";
  });

  return ctx.editMessageText(text, {
    parse_mode: "HTML",
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "btn_back"), callback_data: "rent:menu"}],
      ],
    },
  });
}

async function handleRentMenuAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data;

  const shouldDelete =
    action &&
    [
      "rent:current",
      "rent:history",
      "rent:contract",
      "rent:settings",
      "rent:support",
      "rent:menu",
    ].includes(action);
  if (shouldDelete) {
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
  }

  if (action === "rent:book") {
    ctx.session.booking = null;
    return require("../commands/book")(ctx);
  }

  if (action === "rent:current") {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (!user) return ctx.reply(t(lang, "not_registered"));
    const rentals = await db("rentals")
      .join("bikes", "rentals.bike_id", "bikes.id")
      .where("rentals.user_id", user.id)
      .whereIn("rentals.status", CURRENT_RENTAL_STATUSES)
      .select(
        "rentals.*",
        "bikes.name as bike_name",
        "bikes.description as bike_desc"
      )
      .orderBy("rentals.created_at", "desc");

    const booking = ctx.session.booking;
    const hasDraft =
      booking &&
      (booking.startDate ||
        booking.endDate ||
        booking.selectedBikeId ||
        booking.totalPrice);

    if (!rentals.length && !hasDraft) {
      return ctx.reply(t(lang, "rent_current_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }

    const keyboard = [];
    let text = `<b>${t(lang, "rent_current_title")}</b>\n\n`;

    if (hasDraft) {
      let draftBike = null;
      if (booking.selectedBikeId) {
        draftBike = await db("bikes")
          .where({id: booking.selectedBikeId})
          .first();
      }

      let startLabel = booking.startDate
        ? dayjs(booking.startDate).format("DD.MM.YYYY")
        : "-";
      let endLabel = booking.endDate
        ? dayjs(booking.endDate).format("DD.MM.YYYY")
        : "-";
      if (booking.startTime) startLabel += ` ${booking.startTime}`;
      if (booking.endTime) endLabel += ` ${booking.endTime}`;

      text += `<b>${t(lang, "rent_current_draft_title")}</b>\n`;
      if (booking.startDate && booking.endDate) {
        text += `${tHtml(lang, "rent_details_dates", {
          start: startLabel,
          end: endLabel,
        })}\n`;
      }
      if (draftBike) {
        text += `${tHtml(lang, "rent_details_model", {
          model: draftBike.name,
        })}\n`;
      }
      if (booking.totalPrice || booking.priceUnknown) {
        text += `${tHtml(lang, "rent_details_price", {
          price: booking.priceUnknown
            ? t(lang, "booking_price_tbd")
            : booking.totalPrice,
        })}\n`;
      }
      text += `${t(lang, "rent_current_draft_hint")}\n\n`;

      if (booking.startDate && booking.endDate && booking.selectedBikeId) {
        keyboard.push([
          {
            text: t(lang, "rent_current_draft_continue_btn"),
            callback_data: `book:select_bike:${booking.selectedBikeId}`,
          },
        ]);
      } else if (booking.startDate && booking.endDate) {
        keyboard.push([
          {
            text: t(lang, "booking_choose_bike_btn"),
            callback_data: "book:show_available_bikes",
          },
        ]);
      } else {
        keyboard.push([{text: t(lang, "rent_btn_book"), callback_data: "rent:book"}]);
      }
    }

    rentals.forEach((r) => {
      const statusKey = `rent_status_${r.status}`;
      const statusLabel = t(lang, statusKey) || r.status;
      const startLabel = r.start_at
        ? dayjs(r.start_at).format("DD.MM.YYYY HH:mm")
        : dayjs(r.start_date).format("DD.MM.YYYY");
      const endLabel = r.end_at
        ? dayjs(r.end_at).format("DD.MM.YYYY HH:mm")
        : dayjs(r.end_date).format("DD.MM.YYYY");
      text += `🏍️ <b>${escapeHtml(r.bike_name)}</b>\n`;
      text += `ID: ${escapeHtml(r.booking_public_id || r.id)}\n`;
      text += `${tHtml(lang, "rent_details_status", {status: statusLabel})}\n`;
      text += `${tHtml(lang, "rent_details_dates", {
        start: startLabel,
        end: endLabel,
      })}\n`;
      text += `${tHtml(lang, "rent_details_price", {
        price: r.total_price || t(lang, "booking_price_tbd"),
      })}\n`;
      if (r.helmets_qty || r.delivery_required) {
        text += `${tHtml(lang, "rent_details_helmets", {helmets: r.helmets_qty || 0})}\n`;
        text += `${tHtml(lang, "rent_details_delivery", {
          delivery: r.delivery_required
            ? t(lang, "booking_options_delivery_on")
            : t(lang, "booking_options_delivery_off"),
        })}\n`;
      }
      if (r.delivery_address) {
        text += `${tHtml(lang, "rent_details_address", {
          address: r.delivery_address,
        })}\n`;
      }
      if (r.comment) {
        text += `${tHtml(lang, "rent_details_notes", {notes: r.comment})}\n`;
      }
      text += "\n";

      const row = [];
      if (r.status === "process") {
        row.push({
          text: t(lang, "rent_action_edit"),
          callback_data: `rent:details:${r.id}`,
        });
      } else {
        row.push({
          text: t(lang, "rent_action_details"),
          callback_data: `rent:details:${r.id}`,
        });
        if (USER_CANCELLABLE_RENTAL_STATUSES.includes(r.status)) {
          row.push({
            text: t(lang, "rent_action_cancel"),
            callback_data: `rent:cancel:${r.id}`,
          });
        }
      }
      keyboard.push(row);
    });
    keyboard.push([{text: t(lang, "btn_main_menu"), callback_data: "home"}]);

    return ctx.reply(text, {parse_mode: "HTML", reply_markup: {inline_keyboard: keyboard}});
  }

  if (action === "rent:history") {
    const user = await db("users").where({telegram_id: ctx.from.id}).first();
    if (!user) return ctx.reply(t(lang, "not_registered"));
    const rentals = await db("rentals")
      .leftJoin("bikes", "rentals.bike_id", "bikes.id")
      .where("rentals.user_id", user.id)
      .whereIn("status", HISTORY_RENTAL_STATUSES)
      .select("rentals.*", "bikes.name as bike_name")
      .orderBy("rentals.created_at", "desc");

    if (!rentals.length) {
      return ctx.reply(t(lang, "rent_history_empty"), {
        reply_markup: getRentMenuKeyboard(lang),
      });
    }

    let text = `<b>${t(lang, "rent_btn_history")}</b>\n\n`;
    const keyboard = [];
    rentals.forEach((r) => {
      const statusKey = `rent_status_${r.status}`;
      const statusLabel = t(lang, statusKey) || r.status;
      const startLabel = r.start_at
        ? dayjs(r.start_at).format("DD.MM.YYYY HH:mm")
        : r.start_date
        ? dayjs(r.start_date).format("DD.MM.YYYY")
        : "-";
      const endLabel = r.end_at
        ? dayjs(r.end_at).format("DD.MM.YYYY HH:mm")
        : r.end_date
        ? dayjs(r.end_date).format("DD.MM.YYYY")
        : "-";
      text += `🛵 <b>${escapeHtml(r.bike_name || "-")}</b>\n`;
      text += `ID: ${escapeHtml(r.booking_public_id || r.id)}\n`;
      text += `${tHtml(lang, "rent_details_dates", {
        start: startLabel,
        end: endLabel,
      })}\n`;
      text += `${tHtml(lang, "rent_details_price", {
        price: r.total_price || t(lang, "booking_price_tbd"),
      })}\n`;
      text += `${tHtml(lang, "rent_details_status", {status: statusLabel})}\n\n`;
      keyboard.push([{text: t(lang, "rent_action_details"), callback_data: `rent:details:${r.id}`}]);
    });
    keyboard.push([{text: t(lang, "btn_main_menu"), callback_data: "home"}]);

    return ctx.reply(text, {parse_mode: "HTML", reply_markup: {inline_keyboard: keyboard}});
  }

  if (action === "rent:contract") {
    return ctx.reply(t(lang, "conditions_info"), {
      parse_mode: "HTML",
      reply_markup: getRentMenuKeyboard(lang),
    });
  }

  if (action === "rent:payment") {
    return sendDepositInfo(ctx, lang);
  }

  if (action === "rent:menu") {
    return sendRentMenu(ctx, lang);
  }

  if (action === "rent:settings") {
    return sendAccountMenu(ctx, lang);
  }

  if (action === "rent:support") {
    return sendSupportMenu(ctx, lang);
  }
}

module.exports = {sendRentMenu, handleRentMenuAction};
