// knexfile.js
const path = require("path");

require("dotenv").config({path: path.join(__dirname, ".env")});

const MIGRATIONS_DIRECTORY = path.join(__dirname, "migrations");
const DEV_CONNECTION = {
  host: process.env.PG_HOST || "127.0.0.1",
  port: Number(process.env.PG_PORT || 5432),
  user: process.env.PG_USER || "driverbot_user",
  password: process.env.PG_PASSWORD,
  database: process.env.PG_DATABASE || "driverbot",
};

const PROD_CONNECTION = process.env.DATABASE_URL
  ? {
      connectionString: process.env.DATABASE_URL,
      // для большинства managed‑PG (Railway/Render/Heroku/etc)
      ssl: process.env.PG_SSL === "false" ? false : {rejectUnauthorized: false},
    }
  : {
      host: process.env.PG_HOST,
      port: Number(process.env.PG_PORT || 5432),
      user: process.env.PG_USER,
      password: process.env.PG_PASSWORD,
      database: process.env.PG_DATABASE,
      ssl: process.env.PG_SSL === "true" ? {rejectUnauthorized: false} : false,
    };

module.exports = {
  development: {
    client: "pg",
    connection: DEV_CONNECTION,
    pool: {min: 0, max: 10},
    migrations: {directory: MIGRATIONS_DIRECTORY},
  },

  production: {
    client: "pg",
    connection: PROD_CONNECTION,
    pool: {min: 2, max: 20},
    migrations: {directory: MIGRATIONS_DIRECTORY},
  },
};
