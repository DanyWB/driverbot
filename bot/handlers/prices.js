const fs = require("fs");
const path = require("path");
const {InputFile} = require("grammy");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");

const PRICE_IMAGES = {
  low: ["low.png"],
  middle: ["middle.png", "miggle.png"],
  high: ["high.png"],
};

const SEASON_LABELS = {
  low: "prices_season_low",
  middle: "prices_season_middle",
  high: "prices_season_high",
};

const PRICE_FILE_IDS = {};

function getPricesMenuKeyboard(lang) {
  return {
    inline_keyboard: [
      [{text: t(lang, SEASON_LABELS.high), callback_data: "prices:season:high"}],
      [
        {
          text: t(lang, SEASON_LABELS.middle),
          callback_data: "prices:season:middle",
        },
      ],
      [{text: t(lang, SEASON_LABELS.low), callback_data: "prices:season:low"}],
      [{text: t(lang, "btn_main_menu"), callback_data: "prices:back"}],
    ],
  };
}

async function sendPricesMenu(ctx, langOverride) {
  const lang = langOverride || getCtxLang(ctx);
  if (isLaravelMode()) {
    return ctx.reply(t(lang, "prices_dynamic_hint"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "conditions_book_btn"), callback_data: "book:start"}],
          [{text: t(lang, "btn_main_menu"), callback_data: "prices:back"}],
        ],
      },
    });
  }
  return ctx.reply(t(lang, "prices_choose_season"), {
    reply_markup: getPricesMenuKeyboard(lang),
  });
}

async function safeAnswer(ctx, text) {
  try {
    if (text) {
      await ctx.answerCallbackQuery({text});
      return;
    }
    await ctx.answerCallbackQuery();
  } catch (e) {
    // ignore expired or invalid queries
  }
}

async function handlePricesAction(ctx) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data || "";

  if (action === "prices:back") {
    await safeAnswer(ctx);
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return require("../commands/start")(ctx);
  }

  if (action === "prices:menu") {
    await safeAnswer(ctx);
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return sendPricesMenu(ctx, lang);
  }

  if (isLaravelMode()) {
    await safeAnswer(ctx);
    return sendPricesMenu(ctx, lang);
  }

  const match = action.match(/^prices:season:(low|middle|high)$/);
  if (!match) return;

  const season = match[1];
  await safeAnswer(ctx, t(lang, "prices_loading"));

  const candidates = PRICE_IMAGES[season] || [];
  let filePath = "";
  for (const filename of candidates) {
    const candidatePath = path.join(
      __dirname,
      "..",
      "images",
      "prices",
      filename
    );
    if (!filePath || fs.existsSync(candidatePath)) {
      filePath = candidatePath;
    }
    if (fs.existsSync(candidatePath)) break;
  }
  const caption = t(lang, SEASON_LABELS[season]);

  if (!filePath || !fs.existsSync(filePath)) {
    return ctx.reply(t(lang, "prices_image_missing"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "prices:menu"}],
        ],
      },
    });
  }

  const cachedFileId = PRICE_FILE_IDS[season];

  try {
    const photoPayload = cachedFileId ? cachedFileId : new InputFile(filePath);
    const result = await ctx.replyWithPhoto(photoPayload, {
      caption,
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "prices:menu"}],
        ],
      },
    });
    if (!cachedFileId && Array.isArray(result.photo) && result.photo.length) {
      PRICE_FILE_IDS[season] = result.photo[result.photo.length - 1].file_id;
    }
    try {
      await ctx.deleteMessage();
    } catch (e) {
      // ignore delete errors
    }
    return result;
  } catch (e) {
    return ctx.reply(t(lang, "prices_image_missing"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "prices:menu"}],
        ],
      },
    });
  }
}

module.exports = {sendPricesMenu, handlePricesAction};
