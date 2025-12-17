const db = require("../connect");

// Persists ctx.session in the database between updates.
module.exports = async (ctx, next) => {
  if (!ctx.from) return next();

  const row = await db("sessions").where({user_id: ctx.from.id}).first();
  ctx.session = row ? row.data : {};

  await next();

  if (ctx.session) {
    await db("sessions")
      .insert({user_id: ctx.from.id, data: ctx.session})
      .onConflict("user_id")
      .merge({data: ctx.session});
  }
};
