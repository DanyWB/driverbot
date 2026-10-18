const db = require("../connect");
const {t, getCtxLang} = require("../utils/i18n");
const {isLaravelMode} = require("../config/runtime");
const {markTermsAccepted, restoreTermsAcceptance} = require("../services/sessionCartService");
const gateway = require("../services/laravelGateway");
const {botScreenRenderer} = require("../services/botScreenRenderer");

const TERMS_ORIGINS = new Set(["main_menu", "booking_confirmation"]);

function normalizeTermsOrigin(value) {
  return TERMS_ORIGINS.has(value) ? value : "main_menu";
}

async function acceptCurrentTerms(ctx, api = gateway) {
  // Accept the version actually shown, never a newer unseen version.
  await api.acceptTerms(ctx.from.id, ctx.session.viewedTermsVersion);
  markTermsAccepted(ctx, ctx.session.viewedTermsVersion);
}

function conditionsKeyboard(lang, accepted) {
  return {
    inline_keyboard: [
      [{
        text: accepted
          ? t(lang, "conditions_accepted_label")
          : t(lang, "conditions_accept_btn"),
        callback_data: accepted ? "noop" : "conditions:accept",
      }],
      [{text: t(lang, "rent_btn_contract"), callback_data: "conditions:contract"}],
      [{text: t(lang, "btn_back"), callback_data: "conditions:back"}],
    ],
  };
}

async function hasAcceptedTerms(ctx, api = gateway) {
  if (isLaravelMode()) {
    restoreTermsAcceptance(ctx, await api.getProfile(ctx.from.id));
    return Boolean(ctx.session?.acceptTerms && ctx.session?.acceptedTermsVersion);
  }
  if (ctx.session?.acceptTerms) return true;

  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (!user) return false;
  const accepted = Boolean(await db("rentals")
    .where({user_id: user.id, status: "process", accept_terms: true})
    .first());
  if (accepted) ctx.session.acceptTerms = true;
  return accepted;
}

async function sendConditions(ctx, langOverride, options = {}) {
  const lang = langOverride || getCtxLang(ctx);
  const origin = normalizeTermsOrigin(
    options.origin || ctx.session?.termsOrigin || "main_menu"
  );
  if (ctx.session) ctx.session.termsOrigin = origin;
  const api = options.gateway || gateway;
  const accepted = await hasAcceptedTerms(ctx, api);
  if (isLaravelMode()) {
    const configuration = await api.getConfiguration({refresh: true});
    ctx.session.viewedTermsVersion = configuration.terms_version;
  }

  return (options.renderer || botScreenRenderer).renderText(ctx, {
    screen: "conditions",
    text: `${t(lang, "booking_request_policy")}\n\n${t(lang, "conditions_info")}`,
    parseMode: "HTML",
    replyMarkup: conditionsKeyboard(lang, accepted),
    returnContext: {termsOrigin: origin},
    navigationMode: options.navigationMode || "push",
  });
}

async function persistTermsAcceptance(ctx, api = gateway) {
  if (isLaravelMode()) {
    await acceptCurrentTerms(ctx, api);
    return;
  }

  const user = await db("users").where({telegram_id: ctx.from.id}).first();
  if (user) {
    await db("rentals")
      .where({user_id: user.id, status: "process"})
      .update({accept_terms: true});
  }
  ctx.session.acceptTerms = true;
}

async function returnFromConditions(ctx, options = {}) {
  const origin = normalizeTermsOrigin(
    options.origin || ctx.session?.termsOrigin || "main_menu"
  );
  if (ctx.session) ctx.session.termsOrigin = null;

  if (origin === "booking_confirmation") {
    return require("./book_draft").showBookingDraft(ctx, {
      renderer: options.renderer,
      origin: "conditions",
      navigationMode: "back",
    });
  }
  return require("./main_menu").showMainMenu(ctx, undefined, {
    renderer: options.renderer,
    navigationMode: "reset",
  });
}

async function handleConditionsActionWithDeps(ctx, dependencies = {}) {
  const action = ctx.callbackQuery?.data;
  const lang = getCtxLang(ctx);
  const renderer = dependencies.renderer || botScreenRenderer;
  const api = dependencies.gateway || gateway;

  if (action === "conditions:open") {
    return sendConditions(ctx, lang, {renderer, gateway: api});
  }
  if (action === "conditions:back") {
    return returnFromConditions(ctx, {renderer});
  }
  if (!["conditions:accept", "conditions:accept_toggle"].includes(action)) {
    return undefined;
  }

  const origin = normalizeTermsOrigin(
    ctx.session?.termsOrigin ||
      (action === "conditions:accept_toggle"
        ? "booking_confirmation"
        : "main_menu")
  );
  if (!(await hasAcceptedTerms(ctx, api))) {
    if (isLaravelMode()) {
      const configuration = await api.getConfiguration({refresh: true});
      if (!ctx.session.viewedTermsVersion || ctx.session.viewedTermsVersion !== configuration.terms_version) {
        return sendConditions(ctx, lang, {renderer, gateway: api, origin});
      }
    }
    await persistTermsAcceptance(ctx, api);
  }
  return returnFromConditions(ctx, {origin, renderer});
}

async function handleConditionsAction(ctx) {
  return handleConditionsActionWithDeps(ctx);
}

module.exports = {
  TERMS_ORIGINS,
  conditionsKeyboard,
  handleConditionsAction,
  handleConditionsActionWithDeps,
  normalizeTermsOrigin,
  returnFromConditions,
  sendConditions,
};
