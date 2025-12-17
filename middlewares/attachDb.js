const db = require("../connect");

// Attaches a shared DB instance to the context for every update.
module.exports = (ctx, next) => {
  ctx.db = db;
  return next();
};
