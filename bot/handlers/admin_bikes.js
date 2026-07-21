const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {
  PROFILES,
  SEASONS,
  DAYS,
  calculatePriceRows,
  ROUNDING_MODE,
  ROUNDING_STEP,
  ROUNDING_MIN_DAYS,
} = require("../utils/pricingProfiles");
const {VEHICLE_TYPE, normalizeVehicleType} = require("../utils/vehicleTypes");

let hasBikeIdColumnCache = null;

async function hasBikeIdColumn() {
  if (hasBikeIdColumnCache !== null) return hasBikeIdColumnCache;
  try {
    hasBikeIdColumnCache = await db.schema.hasColumn("bikes", "bike_id");
  } catch (e) {
    hasBikeIdColumnCache = false;
  }
  return hasBikeIdColumnCache;
}

function slugify(value) {
  const base = String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "")
    .replace(/-+/g, "-");
  return base || `bike-${Date.now()}`;
}

async function ensureUniqueBikeId(candidate) {
  const hasColumn = await hasBikeIdColumn();
  if (!hasColumn) return null;
  let bikeId = candidate;
  let suffix = 2;
  while (await db("bikes").where({bike_id: bikeId}).first()) {
    bikeId = `${candidate}-${suffix}`;
    suffix += 1;
  }
  return bikeId;
}

async function ensureAdmin(ctx, lang) {
  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user || !user.is_admin) {
    await ctx.answerCallbackQuery({text: t(lang, "admin_not_allowed"), show_alert: true});
    return null;
  }
  return user;
}

async function safeAnswer(ctx, text) {
  try {
    if (text) {
      await ctx.answerCallbackQuery({text});
      return;
    }
    await ctx.answerCallbackQuery();
  } catch (e) {
    // ignore
  }
}

async function safeEditMessage(ctx, text, options) {
  try {
    return await ctx.editMessageText(text, options);
  } catch (e) {
    return ctx.reply(text, options);
  }
}

function getAdminBikeSession(ctx) {
  if (!ctx.session.adminBike) {
    ctx.session.adminBike = {mode: null, data: {}, stage: null};
  }
  return ctx.session.adminBike;
}

function clearAdminBikeSession(ctx) {
  ctx.session.adminBike = null;
  ctx.session.step = null;
}

async function renderBikeList(ctx, lang, actionPrefix) {
  const bikes = await db("bikes")
    .select("id", "name", "is_active", "vehicle_type")
    .orderBy("vehicle_type")
    .orderBy("category_id")
    .orderBy("name");

  if (!bikes.length) {
    return safeEditMessage(ctx, t(lang, "admin_bikes_empty"), {
      reply_markup: {
        inline_keyboard: [[{text: t(lang, "btn_back"), callback_data: "admin:menu"}]],
      },
    });
  }

  const keyboard = bikes.map((bike) => [
    {
      text: `${bike.is_active ? "[on]" : "[off]"} [${normalizeVehicleType(bike.vehicle_type)}] ${bike.name}`,
      callback_data: `${actionPrefix}:${bike.id}`,
    },
  ]);
  keyboard.push([{text: t(lang, "btn_back"), callback_data: "admin:menu"}]);

  return safeEditMessage(ctx, t(lang, "admin_bikes_list_title"), {
    reply_markup: {inline_keyboard: keyboard},
  });
}

function buildBikeEditMenu(lang, bike) {
  const statusLabel = bike.is_active
    ? t(lang, "admin_bike_status_active")
    : t(lang, "admin_bike_status_inactive");

  const toggleLabel = bike.is_active
    ? t(lang, "admin_bike_deactivate_btn")
    : t(lang, "admin_bike_activate_btn");

  const text =
    `${t(lang, "admin_bike_edit_title")}\n` +
    `Type: ${normalizeVehicleType(bike.vehicle_type)}\n` +
    `${t(lang, "admin_bike_label_name")}: ${bike.name}\n` +
    `${t(lang, "admin_bike_label_status")}: ${statusLabel}`;

  return {
    text,
    reply_markup: {
      inline_keyboard: [
        [{text: t(lang, "admin_bike_edit_name_btn"), callback_data: "admin:bike:rename"}],
        [{text: t(lang, "admin_bike_edit_desc_btn"), callback_data: "admin:bike:edit_desc"}],
        [{text: t(lang, "admin_bike_edit_emoji_btn"), callback_data: "admin:bike:edit_emoji"}],
        [{text: t(lang, "admin_bike_edit_category_btn"), callback_data: "admin:bike:edit_category"}],
        [{text: t(lang, "admin_bike_edit_prices_btn"), callback_data: "admin:bike:edit_prices"}],
        [{text: t(lang, "admin_bike_copy_prices_btn"), callback_data: "admin:bike:copy_prices"}],
        [{text: toggleLabel, callback_data: "admin:bike:toggle"}],
        [{text: t(lang, "btn_back"), callback_data: "admin:bikes:edit"}],
      ],
    },
  };
}

async function renderCategoryKeyboard(lang) {
  const categories = await db("categories").select("id", "name").orderBy("id");
  const keyboard = categories.map((category) => [
    {
      text: category.name,
      callback_data: `admin:bike:set_category:${category.id}`,
    },
  ]);
  keyboard.push([{text: t(lang, "btn_back"), callback_data: "admin:bikes"}]);
  return {inline_keyboard: keyboard};
}

function buildProfileKeyboard(lang) {
  const keyboard = Object.entries(PROFILES).map(([key, profile]) => [
    {text: profile.label, callback_data: `admin:bike:profile:${key}`},
  ]);
  keyboard.push([{text: t(lang, "btn_back"), callback_data: "admin:bikes"}]);
  return {inline_keyboard: keyboard};
}

function buildPricingMethodKeyboard(lang) {
  return {
    inline_keyboard: [
      [
        {
          text: t(lang, "admin_bike_pricing_method_coeff"),
          callback_data: "admin:bike:pricing:coeff",
        },
      ],
      [
        {
          text: t(lang, "admin_bike_pricing_method_copy"),
          callback_data: "admin:bike:pricing:copy",
        },
      ],
      [{text: t(lang, "btn_back"), callback_data: "admin:bikes"}],
    ],
  };
}

function promptPricingMethod(ctx, lang) {
  return ctx.reply(t(lang, "admin_bike_prompt_pricing_method"), {
    reply_markup: buildPricingMethodKeyboard(lang),
  });
}

function parseBasePrices(text) {
  const matches = String(text || "").match(/[\d.,]+/g);
  if (!matches || matches.length < 3) return null;
  const values = matches.map((val) => Number(val.replace(",", ".")));
  if (values.some((val) => !Number.isFinite(val))) return null;
  return {high: values[0], middle: values[1], low: values[2]};
}

function formatPricePreview(lang, profileKey, basePrices, priceRows) {
  const profile = PROFILES[profileKey];
  const profileLabel = profile ? profile.label : profileKey;

  const rowsBySeason = {};
  for (const season of SEASONS) {
    rowsBySeason[season.id] = {};
  }
  priceRows.forEach((row) => {
    if (!rowsBySeason[row.season_id]) rowsBySeason[row.season_id] = {};
    rowsBySeason[row.season_id][row.days_type] = row.total;
  });

  const daysOrder = ["1d", "7d", "14d", "21d", "month"];
  const formatSeason = (seasonKey) => {
    const season = SEASONS.find((item) => item.key === seasonKey);
    if (!season) return "";
    const labelKey =
      seasonKey === "high"
        ? "prices_season_high"
        : seasonKey === "middle"
        ? "prices_season_middle"
        : "prices_season_low";
    const totals = daysOrder
      .map((type) => {
        const total = rowsBySeason[season.id]?.[type];
        return total ? `${type}: ${total}` : `${type}: -`;
      })
      .join(" | ");
    return `${t(lang, labelKey)}: ${totals}`;
  };

  const roundingInfo = `${ROUNDING_MODE} ${ROUNDING_STEP} (>=${ROUNDING_MIN_DAYS}d)`;

  return (
    `${t(lang, "admin_bike_price_preview_title")}\n` +
    `${t(lang, "admin_bike_price_profile")}: ${profileLabel}\n` +
    `${t(lang, "admin_bike_price_rounding")}: ${roundingInfo}\n` +
    `${t(lang, "admin_bike_price_base")}: ` +
    `${basePrices.high} / ${basePrices.middle} / ${basePrices.low}\n\n` +
    `${formatSeason("high")}\n` +
    `${formatSeason("middle")}\n` +
    `${formatSeason("low")}`
  );
}

async function buildPricePreviewFromBike(lang, bikeId) {
  const priceRows = await db("bike_prices")
    .where({bike_id: bikeId})
    .select("season_id", "days_type", "price_per_day");

  const totals = priceRows.map((row) => {
    const days = DAYS[row.days_type]?.days || 1;
    return {
      season_id: row.season_id,
      days_type: row.days_type,
      total: Math.round(Number(row.price_per_day) * days),
    };
  });

  const daysOrder = ["1d", "7d", "14d", "21d", "month"];
  const rowsBySeason = {};
  totals.forEach((row) => {
    if (!rowsBySeason[row.season_id]) rowsBySeason[row.season_id] = {};
    rowsBySeason[row.season_id][row.days_type] = row.total;
  });

  const formatSeason = (seasonKey) => {
    const season = SEASONS.find((item) => item.key === seasonKey);
    if (!season) return "";
    const labelKey =
      seasonKey === "high"
        ? "prices_season_high"
        : seasonKey === "middle"
        ? "prices_season_middle"
        : "prices_season_low";
    const totalsLine = daysOrder
      .map((type) => {
        const total = rowsBySeason[season.id]?.[type];
        return total ? `${type}: ${total}` : `${type}: -`;
      })
      .join(" | ");
    return `${t(lang, labelKey)}: ${totalsLine}`;
  };

  return (
    `${t(lang, "admin_bike_price_preview_title")}\n` +
    `${formatSeason("high")}\n` +
    `${formatSeason("middle")}\n` +
    `${formatSeason("low")}`
  );
}

async function createBikeFromSession(ctx, lang) {
  const adminBike = getAdminBikeSession(ctx);
  const data = adminBike.data || {};
  if (!data.name || !data.category_id) {
    return ctx.reply(t(lang, "admin_bike_missing_data"));
  }
  if (!data.price_rows?.length && !data.copy_from_bike_id) {
    return ctx.reply(t(lang, "admin_bike_missing_data"));
  }
  const hasBikeId = await hasBikeIdColumn();
  let bikeId = null;
  if (hasBikeId) {
    const baseId =
      data.bike_id && data.bike_id.trim() ? data.bike_id.trim() : slugify(data.name);
    bikeId = await ensureUniqueBikeId(baseId);
  }

  await db.transaction(async (trx) => {
    const insertData = {
      name: data.name,
      category_id: data.category_id,
      description: data.description || null,
      emoji: data.emoji || null,
      vehicle_type: normalizeVehicleType(data.vehicle_type || VEHICLE_TYPE.BIKE),
      inventory_code: data.inventory_code || null,
      sort_order: Number.isFinite(Number(data.sort_order)) ? Number(data.sort_order) : 0,
      is_active: true,
      pricing_profile: data.pricing_profile || null,
    };
    if (hasBikeId) {
      insertData.bike_id = bikeId;
    }

    const inserted = await trx("bikes").insert(insertData).returning("id");
    const newBikeId = Array.isArray(inserted) ? inserted[0].id || inserted[0] : inserted;

    let priceRows = data.price_rows || [];
    if (data.copy_from_bike_id) {
      const sourceRows = await trx("bike_prices")
        .where({bike_id: data.copy_from_bike_id})
        .select("season_id", "days_type", "price_per_day");
      priceRows = sourceRows.map((row) => ({
        season_id: row.season_id,
        days_type: row.days_type,
        price_per_day: row.price_per_day,
      }));
    }

    if (priceRows.length) {
      const payload = priceRows.map((row) => ({
        bike_id: newBikeId,
        season_id: row.season_id,
        days_type: row.days_type,
        price_per_day: row.price_per_day,
      }));
      await trx("bike_prices").insert(payload);
    }
  });

  clearAdminBikeSession(ctx);
  await ctx.reply(t(lang, "admin_bike_created"));
}

async function updateBikePrices(ctx, lang) {
  const adminBike = getAdminBikeSession(ctx);
  const bikeId = adminBike.bikeId;
  if (!bikeId) return ctx.reply(t(lang, "admin_bike_not_found"));
  if (!adminBike.data.price_rows?.length && !adminBike.data.copy_from_bike_id) {
    return ctx.reply(t(lang, "admin_bike_missing_data"));
  }

  await db.transaction(async (trx) => {
    let priceRows = adminBike.data.price_rows || [];

    if (adminBike.data.copy_from_bike_id) {
      const sourceRows = await trx("bike_prices")
        .where({bike_id: adminBike.data.copy_from_bike_id})
        .select("season_id", "days_type", "price_per_day");
      priceRows = sourceRows.map((row) => ({
        season_id: row.season_id,
        days_type: row.days_type,
        price_per_day: row.price_per_day,
      }));
    }

    await trx("bike_prices").where({bike_id: bikeId}).del();

    if (priceRows.length) {
      const payload = priceRows.map((row) => ({
        bike_id: bikeId,
        season_id: row.season_id,
        days_type: row.days_type,
        price_per_day: row.price_per_day,
      }));
      await trx("bike_prices").insert(payload);
    }

    if (adminBike.data.pricing_profile) {
      await trx("bikes")
        .where({id: bikeId})
        .update({pricing_profile: adminBike.data.pricing_profile});
    }
  });

  clearAdminBikeSession(ctx);
  await ctx.reply(t(lang, "admin_bike_updated"));
}

async function handleAdminBikeStep(ctx) {
  const step = ctx.session?.step;
  const lang = getCtxLang(ctx);
  const adminBike = getAdminBikeSession(ctx);

  if (!adminBike?.mode) return;

  if (step === "admin_bike_name") {
    adminBike.data.name = ctx.message?.text?.trim();
    ctx.session.step = "admin_bike_description";
    return ctx.reply(t(lang, "admin_bike_prompt_description"));
  }

  if (step === "admin_bike_description") {
    const text = ctx.message?.text?.trim();
    adminBike.data.description = text && text !== "-" ? text : null;
    ctx.session.step = "admin_bike_emoji";
    return ctx.reply(t(lang, "admin_bike_prompt_emoji"));
  }

  if (step === "admin_bike_emoji") {
    const text = ctx.message?.text?.trim();
    adminBike.data.emoji = text && text !== "-" ? text : null;
    ctx.session.step = null;
    adminBike.stage = "category";
    return ctx.reply(t(lang, "admin_bike_prompt_category"), {
      reply_markup: await renderCategoryKeyboard(lang),
    });
  }

  if (step === "admin_bike_bike_id") {
    const text = ctx.message?.text?.trim();
    adminBike.data.bike_id = text && text !== "-" ? text : null;
    ctx.session.step = null;
    adminBike.stage = "pricing_method";
    return promptPricingMethod(ctx, lang);
  }

  if (step === "admin_bike_prices_base") {
    const basePrices = parseBasePrices(ctx.message?.text);
    if (!basePrices) {
      return ctx.reply(t(lang, "admin_bike_invalid_price_input"));
    }

    adminBike.data.base_prices = basePrices;
    const rows = calculatePriceRows({
      profileKey: adminBike.data.pricing_profile,
      basePrices,
    });
    adminBike.data.price_rows = rows;
    ctx.session.step = null;
    adminBike.confirmAction = adminBike.mode === "create" ? "create" : "update_prices";

    const preview = formatPricePreview(
      lang,
      adminBike.data.pricing_profile,
      basePrices,
      rows
    );
    return ctx.reply(preview, {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "admin_bike_confirm_btn"), callback_data: "admin:bike:confirm"}],
          [{text: t(lang, "admin_bike_cancel_btn"), callback_data: "admin:bike:cancel"}],
        ],
      },
    });
  }

  if (step === "admin_bike_edit_name") {
    const text = ctx.message?.text?.trim();
    ctx.session.step = null;
    if (!adminBike.bikeId || !text) return;
    await db("bikes").where({id: adminBike.bikeId}).update({name: text});
    const bike = await db("bikes").where({id: adminBike.bikeId}).first();
    const menu = buildBikeEditMenu(lang, bike);
    return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
  }

  if (step === "admin_bike_edit_description") {
    const text = ctx.message?.text?.trim();
    ctx.session.step = null;
    if (!adminBike.bikeId) return;
    await db("bikes")
      .where({id: adminBike.bikeId})
      .update({description: text && text !== "-" ? text : null});
    const bike = await db("bikes").where({id: adminBike.bikeId}).first();
    const menu = buildBikeEditMenu(lang, bike);
    return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
  }

  if (step === "admin_bike_edit_emoji") {
    const text = ctx.message?.text?.trim();
    ctx.session.step = null;
    if (!adminBike.bikeId) return;
    await db("bikes")
      .where({id: adminBike.bikeId})
      .update({emoji: text && text !== "-" ? text : null});
    const bike = await db("bikes").where({id: adminBike.bikeId}).first();
    const menu = buildBikeEditMenu(lang, bike);
    return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
  }

  if (step === "admin_bike_edit_prices_base") {
    const basePrices = parseBasePrices(ctx.message?.text);
    if (!basePrices) {
      return ctx.reply(t(lang, "admin_bike_invalid_price_input"));
    }

    adminBike.data.base_prices = basePrices;
    const rows = calculatePriceRows({
      profileKey: adminBike.data.pricing_profile,
      basePrices,
    });
    adminBike.data.price_rows = rows;
    ctx.session.step = null;
    adminBike.confirmAction = "update_prices";

    const preview = formatPricePreview(
      lang,
      adminBike.data.pricing_profile,
      basePrices,
      rows
    );
    return ctx.reply(preview, {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "admin_bike_confirm_btn"), callback_data: "admin:bike:confirm"}],
          [{text: t(lang, "admin_bike_cancel_btn"), callback_data: "admin:bike:cancel"}],
        ],
      },
    });
  }
}

async function handleAdminBikesCallback(ctx) {
  const lang = getCtxLang(ctx);
  const isAdmin = await ensureAdmin(ctx, lang);
  if (!isAdmin) return;

  const action = ctx.callbackQuery?.data || "";
  const adminBike = getAdminBikeSession(ctx);

  if (action === "admin:bikes") {
    clearAdminBikeSession(ctx);
    await safeAnswer(ctx);
    return safeEditMessage(ctx, t(lang, "admin_bikes_menu_title"), {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "admin_bikes_add_btn"), callback_data: "admin:bikes:add"}],
          [{text: t(lang, "admin_bikes_edit_btn"), callback_data: "admin:bikes:edit"}],
          [{text: t(lang, "btn_back"), callback_data: "admin:menu"}],
        ],
      },
    });
  }

  if (action === "admin:bikes:add") {
    await safeAnswer(ctx);
    adminBike.mode = "create";
    adminBike.data = {};
    adminBike.stage = "name";
    ctx.session.step = "admin_bike_name";
    return ctx.reply(t(lang, "admin_bike_prompt_name"));
  }

  if (action === "admin:bikes:edit") {
    await safeAnswer(ctx);
    return renderBikeList(ctx, lang, "admin:bike:edit");
  }

  if (action.startsWith("admin:bike:edit:")) {
    await safeAnswer(ctx);
    const bikeId = Number(action.split(":")[3]);
    const bike = await db("bikes").where({id: bikeId}).first();
    if (!bike) return ctx.reply(t(lang, "admin_bike_not_found"));
    adminBike.mode = "edit";
    adminBike.bikeId = bikeId;
    adminBike.data = {};
    adminBike.stage = null;
    const menu = buildBikeEditMenu(lang, bike);
    return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
  }

  if (action === "admin:bike:rename") {
    await safeAnswer(ctx);
    ctx.session.step = "admin_bike_edit_name";
    return ctx.reply(t(lang, "admin_bike_prompt_name"));
  }

  if (action === "admin:bike:edit_desc") {
    await safeAnswer(ctx);
    ctx.session.step = "admin_bike_edit_description";
    return ctx.reply(t(lang, "admin_bike_prompt_description"));
  }

  if (action === "admin:bike:edit_emoji") {
    await safeAnswer(ctx);
    ctx.session.step = "admin_bike_edit_emoji";
    return ctx.reply(t(lang, "admin_bike_prompt_emoji"));
  }

  if (action === "admin:bike:edit_category") {
    await safeAnswer(ctx);
    adminBike.stage = "edit_category";
    return ctx.reply(t(lang, "admin_bike_prompt_category"), {
      reply_markup: await renderCategoryKeyboard(lang),
    });
  }

  if (action === "admin:bike:edit_prices") {
    await safeAnswer(ctx);
    adminBike.stage = "edit_prices";
    return ctx.reply(t(lang, "admin_bike_prompt_profile"), {
      reply_markup: buildProfileKeyboard(lang),
    });
  }

  if (action === "admin:bike:copy_prices") {
    await safeAnswer(ctx);
    adminBike.stage = "copy_prices";
    return renderBikeList(ctx, lang, "admin:bike:copy_from");
  }

  if (action === "admin:bike:toggle") {
    await safeAnswer(ctx);
    if (!adminBike.bikeId) return ctx.reply(t(lang, "admin_bike_not_found"));
    const bike = await db("bikes").where({id: adminBike.bikeId}).first();
    if (!bike) return ctx.reply(t(lang, "admin_bike_not_found"));
    await db("bikes")
      .where({id: adminBike.bikeId})
      .update({is_active: !bike.is_active});
    const updated = await db("bikes").where({id: adminBike.bikeId}).first();
    const menu = buildBikeEditMenu(lang, updated);
    return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
  }

  if (action.startsWith("admin:bike:set_category:")) {
    await safeAnswer(ctx);
    const categoryId = Number(action.split(":")[3]);

    if (adminBike.mode === "create" && adminBike.stage === "category") {
      adminBike.data.category_id = categoryId;
      const hasBikeId = await hasBikeIdColumn();
      if (hasBikeId) {
        adminBike.stage = "bike_id";
        ctx.session.step = "admin_bike_bike_id";
        return ctx.reply(t(lang, "admin_bike_prompt_bike_id"));
      }
      adminBike.stage = "pricing_method";
      return promptPricingMethod(ctx, lang);
    }

    if (adminBike.mode === "edit" && adminBike.stage === "edit_category") {
      await db("bikes").where({id: adminBike.bikeId}).update({category_id: categoryId});
      const bike = await db("bikes").where({id: adminBike.bikeId}).first();
      const menu = buildBikeEditMenu(lang, bike);
      return safeEditMessage(ctx, menu.text, {reply_markup: menu.reply_markup});
    }
  }

  if (action === "admin:bike:pricing:coeff") {
    await safeAnswer(ctx);
    adminBike.stage = "pricing_profile";
    return ctx.reply(t(lang, "admin_bike_prompt_profile"), {
      reply_markup: buildProfileKeyboard(lang),
    });
  }

  if (action === "admin:bike:pricing:copy") {
    await safeAnswer(ctx);
    adminBike.stage = "pricing_copy";
    return renderBikeList(ctx, lang, "admin:bike:copy_from");
  }

  if (action.startsWith("admin:bike:profile:")) {
    await safeAnswer(ctx);
    const profileKey = action.split(":")[3];
    if (!PROFILES[profileKey]) return ctx.reply(t(lang, "admin_bike_profile_not_found"));
    adminBike.data.pricing_profile = profileKey;
    if (adminBike.mode === "create") {
      ctx.session.step = "admin_bike_prices_base";
      return ctx.reply(t(lang, "admin_bike_prompt_base_prices"));
    }
    if (adminBike.mode === "edit") {
      ctx.session.step = "admin_bike_edit_prices_base";
      return ctx.reply(t(lang, "admin_bike_prompt_base_prices"));
    }
  }

  if (action.startsWith("admin:bike:copy_from:")) {
    await safeAnswer(ctx);
    const sourceId = Number(action.split(":")[3]);
    const source = await db("bikes").where({id: sourceId}).first();
    if (!source) return ctx.reply(t(lang, "admin_bike_not_found"));

    adminBike.data.copy_from_bike_id = sourceId;
    adminBike.data.pricing_profile = source.pricing_profile || null;
    adminBike.confirmAction = adminBike.mode === "create" ? "create_copy" : "copy_prices";

    const preview = await buildPricePreviewFromBike(lang, sourceId);
    return ctx.reply(`${t(lang, "admin_bike_copy_from_label")}: ${source.name}\n\n${preview}`, {
      reply_markup: {
        inline_keyboard: [
          [{text: t(lang, "admin_bike_confirm_btn"), callback_data: "admin:bike:confirm"}],
          [{text: t(lang, "admin_bike_cancel_btn"), callback_data: "admin:bike:cancel"}],
        ],
      },
    });
  }

  if (action === "admin:bike:confirm") {
    await safeAnswer(ctx);
    if (adminBike.confirmAction === "create" || adminBike.confirmAction === "create_copy") {
      return createBikeFromSession(ctx, lang);
    }
    if (adminBike.confirmAction === "update_prices" || adminBike.confirmAction === "copy_prices") {
      return updateBikePrices(ctx, lang);
    }
    return ctx.reply(t(lang, "admin_bike_confirm_failed"));
  }

  if (action === "admin:bike:cancel") {
    await safeAnswer(ctx);
    clearAdminBikeSession(ctx);
    return ctx.reply(t(lang, "admin_bike_cancelled"));
  }
}

module.exports = {
  handleAdminBikesCallback,
  handleAdminBikeStep,
};
