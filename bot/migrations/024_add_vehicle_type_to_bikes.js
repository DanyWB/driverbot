const VEHICLE_TYPES = ["bike", "car"];
const typeCheckValues = VEHICLE_TYPES.map((type) => `'${type}'`).join(", ");

exports.up = async function (knex) {
  await knex.schema.alterTable("bikes", function (table) {
    table.string("vehicle_type").notNullable().defaultTo("bike");
    table.string("inventory_code");
    table.integer("sort_order").notNullable().defaultTo(0);
  });

  await knex.raw(`
    ALTER TABLE bikes
    ADD CONSTRAINT bikes_vehicle_type_check
    CHECK (vehicle_type IN (${typeCheckValues}))
  `);

  await knex.raw(`
    CREATE UNIQUE INDEX IF NOT EXISTS bikes_inventory_code_unique
    ON bikes (inventory_code)
    WHERE inventory_code IS NOT NULL
  `);

  await knex.raw(`
    CREATE INDEX IF NOT EXISTS bikes_vehicle_type_active_category_idx
    ON bikes (vehicle_type, is_active, category_id, sort_order, name)
  `);
};

exports.down = async function (knex) {
  await knex.raw("DROP INDEX IF EXISTS bikes_vehicle_type_active_category_idx");
  await knex.raw("DROP INDEX IF EXISTS bikes_inventory_code_unique");
  await knex.raw("ALTER TABLE bikes DROP CONSTRAINT IF EXISTS bikes_vehicle_type_check");

  await knex.schema.alterTable("bikes", function (table) {
    table.dropColumn("sort_order");
    table.dropColumn("inventory_code");
    table.dropColumn("vehicle_type");
  });
};
