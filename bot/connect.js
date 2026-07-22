const {isLaravelMode} = require("./config/runtime");

if (isLaravelMode()) {
  const unavailable = () => {
    throw new Error("Direct database access is disabled in Laravel mode");
  };
  module.exports = new Proxy(unavailable, {
    get: unavailable,
    apply: unavailable,
  });
} else {
  const knex = require("knex");
  const knexfile = require("./knexfile");
  const environment = process.env.NODE_ENV || "development";
  const config = knexfile[environment];

  if (!config) {
    throw new Error(`Knex config for NODE_ENV="${environment}" not found`);
  }

  module.exports = knex({...config, acquireConnectionTimeout: 10000});
}
