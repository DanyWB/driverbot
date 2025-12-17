// Resets simple step/scenario markers when user navigates via commands or callbacks.
module.exports = async (ctx, next) => {
  const text = ctx.message?.text;
  const isCommand = text && text.startsWith("/");
  const isCallback = Boolean(ctx.callbackQuery);

  if (isCommand || isCallback) {
    if (ctx.session) {
      ctx.session.step = null;
      ctx.session.scenario = null;
    }
  }

  await next();
};
