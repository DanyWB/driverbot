const {
  DEFAULT_NAVIGATION_STACK_LIMIT,
  commitNavigationState,
  getActiveUiMessage,
  navigationStackLimit,
  setActiveUiMessage,
} = require("../utils/navigationState");

function errorDescription(error) {
  return String(
    error?.description ||
      error?.error?.description ||
      error?.response?.description ||
      error?.message ||
      error ||
      "Unknown Telegram API error"
  );
}

function isMessageNotModified(error) {
  return /message is not modified/i.test(errorDescription(error));
}

function resolveChatId(ctx) {
  return (
    ctx.chat?.id ||
    ctx.callbackQuery?.message?.chat?.id ||
    ctx.from?.id ||
    null
  );
}

function ensureSession(ctx) {
  if (!ctx.session || typeof ctx.session !== "object" || Array.isArray(ctx.session)) {
    ctx.session = {};
  }

  return ctx.session;
}

function telegramOptions(options, parseMode, replyMarkup) {
  const result = {...(options || {})};
  if (parseMode !== undefined) result.parse_mode = parseMode;
  if (replyMarkup !== undefined) result.reply_markup = replyMarkup;
  return result;
}

class BotScreenRenderer {
  constructor({logger = console, stackLimit = DEFAULT_NAVIGATION_STACK_LIMIT} = {}) {
    this.logger = logger;
    this.stackLimit = navigationStackLimit(stackLimit);
  }

  async renderDocuments(ctx, {documents, ...screen}) {
    const chatId = resolveChatId(ctx);
    const session = ensureSession(ctx);
    await this.#clearAttachments(ctx, chatId);
    session.uiAttachmentIds = [];
    const {InputFile} = require("grammy");
    for (const document of documents) {
      const message = await ctx.api.sendDocument(chatId, new InputFile(document.path, document.filename));
      session.uiAttachmentIds.push(message.message_id);
    }
    return this.renderText(ctx, {...screen, forceNewMessage: true, keepAttachments: true});
  }

  // An album and its text form one disposable UI screen. Only IDs created here
  // are cleaned up; booking receipts and notifications are never included.
  async renderGallery(ctx, {photos = [], description = "", ...screen}) {
    const chatId = resolveChatId(ctx);
    const session = ensureSession(ctx);
    await this.#clearAttachments(ctx, chatId);
    const ids = [];
    session.uiAttachmentIds = ids;
    try {
      for (let i = 0; i < photos.length; i += 10) {
        const batch = photos.slice(i, i + 10);
        try {
          const messages = batch.length === 1
            ? [await ctx.api.sendPhoto(chatId, batch[0])]
            : await ctx.api.sendMediaGroup(chatId, batch.map(media => ({type: "photo", media})));
          ids.push(...messages.map(message => message.message_id));
        } catch (error) {
          // Only retry an explicit rejection, never an uncertain network result.
          if (batch.length === 1 || Number(error?.error_code) !== 400) throw error;
          for (const photo of batch) {
            try {
              const message = await ctx.api.sendPhoto(chatId, photo);
              ids.push(message.message_id);
            } catch (photoError) {
              if (Number(photoError?.error_code) !== 400) throw photoError;
              this.#warn("Telegram rejected one vehicle photo; continuing the gallery.", {screen: screen.screen});
            }
          }
        }
      }
    } catch (error) {
      this.#warn("Could not send vehicle gallery; keeping text and booking controls.", {
        screen: screen.screen, error: errorDescription(error),
      });
    }
    // Split raw text before escaping so entities and Unicode characters stay intact.
    const {escapeHtml} = require("../utils/html");
    const characters = Array.from(String(description || ""));
    const chunks = [];
    let chunk = "";
    for (const character of characters) {
      const escaped = escapeHtml(character);
      if (chunk.length + escaped.length > 3500) {
        chunks.push(chunk);
        chunk = "";
      }
      chunk += escaped;
    }
    if (chunk) chunks.push(chunk);
    for (const text of chunks) {
      const message = await ctx.api.sendMessage(chatId, text, {parse_mode: "HTML"});
      ids.push(message.message_id);
    }
    return this.renderText(ctx, {...screen, forceNewMessage: true, keepAttachments: true});
  }

  async #clearAttachments(ctx, chatId) {
    const session = ensureSession(ctx);
    const ids = session.uiAttachmentIds || [];
    if (!ids.length) return;
    session.uiAttachmentIds = [];
    for (const id of ids) await this.#deleteActiveBestEffort(ctx, chatId, id, "gallery");
  }

  async renderText(
    ctx,
    {
      screen,
      text,
      parseMode,
      replyMarkup,
      options,
      returnContext = null,
      navigationMode = "push",
      forceNewMessage = false,
      keepAttachments = false,
    }
  ) {
    if (typeof text !== "string") {
      throw new TypeError("Text screens require a string payload");
    }

    return this.#render(ctx, {
      type: "text",
      screen,
      payload: text,
      apiOptions: telegramOptions(options, parseMode, replyMarkup),
      returnContext,
      navigationMode,
      forceNewMessage,
      keepAttachments,
    });
  }

  async renderPhoto(
    ctx,
    {
      screen,
      photo,
      caption,
      parseMode,
      replyMarkup,
      options,
      returnContext = null,
      navigationMode = "push",
      forceNewMessage = false,
    }
  ) {
    if (photo === null || photo === undefined || photo === "") {
      throw new TypeError("Photo screens require a photo payload");
    }

    const apiOptions = telegramOptions(options, parseMode, replyMarkup);
    if (caption !== undefined) apiOptions.caption = caption;

    return this.#render(ctx, {
      type: "photo",
      screen,
      payload: photo,
      apiOptions,
      returnContext,
      navigationMode,
      forceNewMessage,
    });
  }

  async #render(
    ctx,
    {
      type,
      screen,
      payload,
      apiOptions,
      returnContext,
      navigationMode,
      forceNewMessage,
      keepAttachments = false,
    }
  ) {
    await this.#answerCallback(ctx);

    const chatId = resolveChatId(ctx);
    if (chatId === null || chatId === undefined) {
      throw new TypeError("Could not resolve a Telegram chat id for the screen");
    }

    const session = ensureSession(ctx);
    if (!keepAttachments) await this.#clearAttachments(ctx, chatId);
    // Validate navigation inputs before performing a Telegram side effect.
    commitNavigationState(
      {
        currentScreen: session.currentScreen,
        returnContext: session.returnContext,
        navigationStack: Array.isArray(session.navigationStack)
          ? [...session.navigationStack]
          : [],
      },
      {
        screen,
        returnContext,
        mode: navigationMode,
        stackLimit: this.stackLimit,
      }
    );

    const active = getActiveUiMessage(session);
    const canEditText =
      !forceNewMessage &&
      type === "text" &&
      active &&
      (!active.type || active.type === "text");

    if (canEditText) {
      try {
        const result = await ctx.api.editMessageText(
          chatId,
          active.messageId,
          payload,
          apiOptions
        );
        this.#commitState(session, active.messageId, type, {
          screen,
          returnContext,
          navigationMode,
        });
        return {mode: "edited", messageId: active.messageId, result};
      } catch (error) {
        if (isMessageNotModified(error)) {
          this.#commitState(session, active.messageId, type, {
            screen,
            returnContext,
            navigationMode,
          });
          return {
            mode: "unchanged",
            messageId: active.messageId,
            result: null,
          };
        }

        this.#warn("Could not edit the active bot UI message; replacing it.", {
          chatId,
          messageId: active.messageId,
          screen,
          error: errorDescription(error),
        });
      }
    }

    if (active) {
      await this.#deleteActiveBestEffort(ctx, chatId, active.messageId, screen);
    }

    try {
      const result =
        type === "text"
          ? await ctx.api.sendMessage(chatId, payload, apiOptions)
          : await ctx.api.sendPhoto(chatId, payload, apiOptions);
      const messageId = Number(result?.message_id);
      if (!Number.isSafeInteger(messageId) || messageId < 1) {
        throw new Error("Telegram send returned no valid message_id");
      }

      this.#commitState(session, messageId, type, {
        screen,
        returnContext,
        navigationMode,
      });

      return {mode: active ? "replaced" : "sent", messageId, result};
    } catch (error) {
      this.#error("Could not send a bot UI message.", {
        chatId,
        screen,
        error: errorDescription(error),
      });
      throw error;
    }
  }

  async #answerCallback(ctx) {
    if (!ctx.callbackQuery) return;

    try {
      if (typeof ctx.answerCallbackQuery === "function") {
        await ctx.answerCallbackQuery();
      } else if (
        ctx.callbackQuery.id &&
        typeof ctx.api?.answerCallbackQuery === "function"
      ) {
        await ctx.api.answerCallbackQuery(ctx.callbackQuery.id);
      }
    } catch (error) {
      this.#warn("Could not answer a Telegram callback query.", {
        error: errorDescription(error),
      });
    }
  }

  async #deleteActiveBestEffort(ctx, chatId, messageId, screen) {
    try {
      await ctx.api.deleteMessage(chatId, messageId);
    } catch (error) {
      this.#warn("Could not delete the previous bot UI message.", {
        chatId,
        messageId,
        screen,
        error: errorDescription(error),
      });
    }
  }

  #commitState(
    session,
    messageId,
    type,
    {screen, returnContext, navigationMode}
  ) {
    setActiveUiMessage(session, messageId, type);
    commitNavigationState(session, {
      screen,
      returnContext,
      mode: navigationMode,
      stackLimit: this.stackLimit,
    });
  }

  #warn(message, context) {
    if (typeof this.logger?.warn === "function") {
      this.logger.warn(`[bot-screen] ${message}`, context);
    }
  }

  #error(message, context) {
    if (typeof this.logger?.error === "function") {
      this.logger.error(`[bot-screen] ${message}`, context);
    }
  }
}

const botScreenRenderer = new BotScreenRenderer();

module.exports = {
  BotScreenRenderer,
  botScreenRenderer,
  errorDescription,
  isMessageNotModified,
  resolveChatId,
};
