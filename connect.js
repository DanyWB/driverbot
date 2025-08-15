// connect.js
require("dotenv").config();
const knex = require("knex");
const knexfile = require("./knexfile");

const ENV = process.env.NODE_ENV || "development";
const config = knexfile[ENV];

if (!config) {
  throw new Error(`Knex config for NODE_ENV="${ENV}" not found`);
}

const db = knex({
  ...config,
  // необязательно, но полезно на проде:
  acquireConnectionTimeout: 10000,
});

module.exports = db;
