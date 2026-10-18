const {t, getCtxLang} = require("../utils/i18n");
const {escapeHtml} = require("../utils/html");
const gateway = require("../services/laravelGateway");
const {getCart} = require("../services/sessionCartService");
const {botScreenRenderer} = require("../services/botScreenRenderer");
const {historyButtonText} = require("../utils/rentalPresentation");

const PAGE_SIZE = 6;

function pageFromAction(action, scope) {
  const match = String(action || "").match(new RegExp(`^rent:${scope}:(\\d+)$`));
  return match ? Math.max(0, Number(match[1]) || 0) : 0;
}

function normalizeBookingPage(result, page) {
  const rawItems = Array.isArray(result) ? result : result?.items || [];
  const totalValue = Number(Array.isArray(result) ? NaN : result?.total);
  const total = Number.isSafeInteger(totalValue) && totalValue >= 0
    ? totalValue
    : null;
  const items = rawItems.slice(0, PAGE_SIZE);
  const hasNext = total !== null
    ? (page + 1) * PAGE_SIZE < total
    : rawItems.length > PAGE_SIZE || Boolean(result?.hasMore);
  const totalPages = total !== null
    ? Math.max(1, Math.ceil(total / PAGE_SIZE))
    : Math.max(1, page + 1 + (hasNext ? 1 : 0));
  return {items, total, hasNext, totalPages};
}

function appendPaginationRow(keyboard, scope, page, pageResult, lang) {
  const row = [];
  if (page > 0) {
    row.push({text: "←", callback_data: `rent:${scope}:${page - 1}`});
  }
  row.push({
    text: t(lang, "rent_history_page", {
      current: page + 1,
      total: pageResult.totalPages,
    }),
    callback_data: "noop",
  });
  if (pageResult.hasNext) {
    row.push({text: "→", callback_data: `rent:${scope}:${page + 1}`});
  }
  keyboard.push(row);
}

function bookingRows(rentals, scope, lang) {
  return rentals.flatMap((rental) => {
    const publicId = rental.booking_public_id;
    const row = [{
      text: historyButtonText(rental),
      callback_data: `rent:details:${publicId}`,
    }];
    if (scope === "current" && rental.can_cancel) {
      row.push({
        text: t(lang, "rent_action_cancel"),
        callback_data: `rent:cancel:${publicId}`,
      });
    }
    return [row];
  });
}

function listScreenPayload({scope, page, pageResult, drafts = [], lang}) {
  const titleKey = scope === "history" ? "rent_btn_history" : "rent_current_title";
  const text = [
    `<b>${t(lang, titleKey)}</b>`,
    t(lang, "rent_history_page", {
      current: page + 1,
      total: pageResult.totalPages,
    }),
  ];
  const keyboard = [];

  if (scope === "current" && drafts.length) {
    text.push("", `<b>${t(lang, "rent_current_draft_title")}</b>`);
    drafts.forEach((draft) => text.push(`• ${escapeHtml(historyButtonText(draft))}`));
    text.push(t(lang, "rent_current_draft_hint"));
    keyboard.push([{
      text: t(lang, "rent_current_draft_continue_btn"),
      callback_data: "book:draft",
    }]);
  }

  keyboard.push(...bookingRows(pageResult.items, scope, lang));
  appendPaginationRow(keyboard, scope, page, pageResult, lang);
  keyboard.push([{text: t(lang, "btn_back"), callback_data: "rent:menu"}]);
  keyboard.push([{text: t(lang, "btn_main_menu"), callback_data: "menu:main"}]);

  return {text: text.join("\n"), replyMarkup: {inline_keyboard: keyboard}};
}

async function loadBookingPage(ctx, scope, page, dependencies) {
  const options = {limit: PAGE_SIZE + 1, offset: page * PAGE_SIZE};
  if (dependencies.listBookingsPage) {
    return dependencies.listBookingsPage(ctx.from.id, scope, options);
  }
  if (typeof gateway.listBookingsPage === "function") {
    return gateway.listBookingsPage(ctx.from.id, scope, options);
  }
  return gateway.listBookings(ctx.from.id, scope, options);
}

async function renderBookingList(ctx, scope, page, dependencies = {}) {
  const lang = getCtxLang(ctx);
  const renderer = dependencies.renderer || botScreenRenderer;
  const fetched = await loadBookingPage(ctx, scope, page, dependencies);
  const pageResult = normalizeBookingPage(fetched, page);
  const drafts = scope === "current" && page === 0 ? getCart(ctx) : [];

  if (!pageResult.items.length && !drafts.length) {
    return renderer.renderText(ctx, {
      screen: `rent_${scope}`,
      text: t(lang, scope === "history" ? "rent_history_empty" : "rent_current_empty"),
      replyMarkup: {
        inline_keyboard: [
          [{text: t(lang, "btn_back"), callback_data: "rent:menu"}],
          [{text: t(lang, "btn_main_menu"), callback_data: "menu:main"}],
        ],
      },
      returnContext: {scope, page},
      navigationMode: ctx.session?.currentScreen === "rent_details" ? "back" : "replace",
    });
  }

  if (scope === "history") ctx.session.historyPage = page;
  else ctx.session.currentRentalsPage = page;
  const payload = listScreenPayload({scope, page, pageResult, drafts, lang});
  const fromDetails = ctx.session?.currentScreen === "rent_details";
  const pagination = ctx.session?.currentScreen === `rent_${scope}`;
  return renderer.renderText(ctx, {
    screen: `rent_${scope}`,
    text: payload.text,
    parseMode: "HTML",
    replyMarkup: payload.replyMarkup,
    returnContext: {scope, page, total: pageResult.total},
    navigationMode: fromDetails ? "back" : pagination ? "replace" : "push",
  });
}

async function handleLaravelRentMenuActionWithDeps(ctx, dependencies = {}) {
  const lang = getCtxLang(ctx);
  const action = ctx.callbackQuery?.data || "";
  const renderer = dependencies.renderer || botScreenRenderer;

  if (action === "rent:book") return require("../commands/book")(ctx);
  if (action === "rent:menu") {
    return require("./rent_menu").sendRentMenu(ctx, lang, {
      renderer,
      navigationMode: ctx.session?.currentScreen?.startsWith("rent_") ? "back" : "push",
    });
  }
  if (action === "rent:settings") {
    return require("./account_menu").sendAccountMenu(ctx, lang, {
      renderer,
      origin: "rent_menu",
    });
  }
  if (action === "rent:support") {
    return require("./support").sendSupportMenu(ctx, lang, {renderer, origin: "rent_menu"});
  }
  if (action === "rent:contract" || action === "conditions:contract") {
    const path = require("node:path");
    return renderer.renderDocuments(ctx, {
      screen: "rent_contract",
      documents: [{path: path.join(__dirname, "../assets/contracts", `agreement-${lang}.png`), filename: `Drive-Phangan-agreement-${lang}.png`}],
      text: t(lang, "rent_contract_copy"),
      parseMode: "HTML",
      replyMarkup: {inline_keyboard: [[
        {text: t(lang, "btn_back"), callback_data: action === "conditions:contract" ? "conditions:open" : "rent:menu"},
      ]]},
      returnContext: {origin: "rent_menu"},
    });
  }
  if (action === "rent:payment" || /^rent:payment:\d+$/.test(action)) {
    const page = pageFromAction(action, "payment");
    const pageResult = normalizeBookingPage(await loadBookingPage(ctx, "current", page, dependencies), page);
    const lines = [t(lang, "rent_btn_payment")];
    for (const booking of pageResult.items) {
      lines.push("", escapeHtml(historyButtonText(booking)));
      for (const key of ["prepayment", "deposit"]) {
        const payment = booking.payments?.[key];
        lines.push(escapeHtml(t(lang, "rent_payment_line", {
          label: t(lang, `rent_payment_${key}`),
          amount: payment?.amount == null ? t(lang, "booking_price_tbd") : `${payment.amount} ${payment.currency || ""}`,
          status: payment?.amount == null ? "—" : t(lang, payment.received ? "rent_payment_received" : "rent_payment_due"),
        })));
      }
    }
    if (!pageResult.items.length) lines.push(t(lang, "rent_deposit_empty"));
    const keyboard = bookingRows(pageResult.items, "payment", lang);
    if (pageResult.items.length || page > 0) appendPaginationRow(keyboard, "payment", page, pageResult, lang);
    keyboard.push([{text: t(lang, "btn_back"), callback_data: "rent:menu"}]);
    return renderer.renderText(ctx, {
      screen: "rent_payment",
      text: lines.join("\n"),
      parseMode: "HTML",
      replyMarkup: {inline_keyboard: keyboard},
      returnContext: {origin: "rent_menu"},
    });
  }
  if (action === "rent:current" || /^rent:current:\d+$/.test(action)) {
    return renderBookingList(ctx, "current", pageFromAction(action, "current"), dependencies);
  }
  if (action === "rent:history" || /^rent:history:\d+$/.test(action)) {
    return renderBookingList(ctx, "history", pageFromAction(action, "history"), dependencies);
  }
  return undefined;
}

async function handleLaravelRentMenuAction(ctx) {
  return handleLaravelRentMenuActionWithDeps(ctx);
}

module.exports = {
  PAGE_SIZE,
  appendPaginationRow,
  bookingRows,
  handleLaravelRentMenuAction,
  handleLaravelRentMenuActionWithDeps,
  listScreenPayload,
  normalizeBookingPage,
  pageFromAction,
  renderBookingList,
};
