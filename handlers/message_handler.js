const db = require("../connect");
const {
  handleNameStep,
  handlePhoneStep,
  handlePassportStep,
} = require("./registration_steps");
const {detectMainMenuAction} = require("../utils/mainMenu");
const {handleMainMenuAction} = require("./main_menu");
const {t, getCtxLang} = require("../utils/i18n");

module.exports = async (ctx) => {
  const step = ctx.session?.step;
  const telegramId = ctx.from.id;
  const action = detectMainMenuAction(ctx.message?.text);

  if (action) {
    return handleMainMenuAction(ctx, action);
  }

  if (!step) return;

  if (step === "awaiting_comment" && ctx.message?.text) {
    const lang = getCtxLang(ctx);
    const user = await db("users").where({telegram_id: telegramId}).first();
    if (!user) {
      await ctx.reply(t(lang, "user_not_found"));
      return;
    }

    await db("rentals")
      .where({user_id: user.id, status: "process"})
      .update({comment: ctx.message.text});

    ctx.session.step = null;

    const commentReturn = ctx.session.commentReturn;
    let buttonText;
    let callbackData;
    if (commentReturn === "rent:current") {
      buttonText = t(lang, "rent_current_back_btn");
      callbackData = "rent:current";
    } else if (commentReturn === "book:add_rental") {
      buttonText = t(lang, "booking_add_bike_to_rental_btn");
      callbackData = "book:add_rental";
    } else {
      const hasBooking = Boolean(ctx.session.booking);
      buttonText = hasBooking
        ? t(lang, "booking_add_bike_to_rental_btn")
        : t(lang, "rent_btn_current");
      callbackData = hasBooking ? "book:add_rental" : "rent:current";
    }

    ctx.session.commentReturn = null;
    await ctx.reply(t(lang, "booking_comment_saved"), {
      reply_markup: {
        inline_keyboard: [
          [
            {
              text: buttonText,
              callback_data: callbackData,
            },
          ],
        ],
      },
    });
    return;
  }

  if (step === "waiting_for_name") {
    return handleNameStep(ctx);
  }

  if (step === "waiting_for_phone") {
    return handlePhoneStep(ctx);
  }

  if (step === "waiting_for_passport") {
    return handlePassportStep(ctx);
  }

  if (step === "waiting_for_delivery_address") {
    const booking = ctx.session.booking || {};
    booking.deliveryAddress = ctx.message?.text || "";
    ctx.session.booking = booking;
    if (ctx.session.optionsScope === "process") {
      const {persistProcessOptions} = require("./book_options");
      await persistProcessOptions(ctx, booking);
    }
    ctx.session.step = null;
    ctx.session.scenario = null;
    const lang = getCtxLang(ctx);
    await ctx.reply(t(lang, "booking_options_address_saved"));
    return;
  }

  if (step === "waiting_for_notes") {
    const booking = ctx.session.booking || {};
    booking.notes = ctx.message?.text || "";
    ctx.session.booking = booking;
    if (ctx.session.optionsScope === "process") {
      const {persistProcessOptions} = require("./book_options");
      await persistProcessOptions(ctx, booking);
    }
    ctx.session.step = null;
    ctx.session.scenario = null;
    const lang = getCtxLang(ctx);
    await ctx.reply(t(lang, "booking_options_notes_saved"));
    return;
  }

  if (step === "admin_decline_reason" && ctx.message?.text) {
    const rentalId = ctx.session.adminDeclineRentalId;
    ctx.session.step = null;
    ctx.session.adminDeclineRentalId = null;
    const {finalizeDecline} = require("./admin_rental_action");
    return finalizeDecline(ctx, rentalId, ctx.message.text);
  }

  if (step === "admin_deposit_note" && ctx.message?.text) {
    const lang = getCtxLang(ctx);
    const rentalId = ctx.session.adminDepositRentalId;
    ctx.session.step = null;
    ctx.session.adminDepositRentalId = null;

    if (!rentalId) {
      await ctx.reply(t(lang, "admin_rental_not_found"));
      return;
    }

    await db("rentals")
      .where({id: rentalId})
      .update({deposit_note: ctx.message.text.trim()});

    const rental = await db("rentals").where({id: rentalId}).first();
    await ctx.reply(
      t(lang, "admin_deposit_saved", {
        id: rental?.booking_public_id || rentalId,
      })
    );
  }
};
