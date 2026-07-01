const knex = require("knex");
const knexfile = require("../knexfile");

const ENV = process.env.NODE_ENV || "development";
const config = knexfile[ENV];

if (!config) {
  console.error(`Knex config for NODE_ENV="${ENV}" not found`);
  process.exit(1);
}

const db = knex(config);

async function main() {
  const [, pending] = await db.migrate.list();

  if (pending.length) {
    console.error("[fail] Pending migrations:");
    pending.forEach((migration) => console.error(`  - ${migration.file}`));
    process.exitCode = 1;
    return;
  }

  console.log("[ok] No pending migrations");
}

main()
  .catch((error) => {
    console.error(error);
    process.exitCode = 1;
  })
  .finally(() => db.destroy());
