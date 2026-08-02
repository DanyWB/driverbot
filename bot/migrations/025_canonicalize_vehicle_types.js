exports.up = async function (knex) {
  await knex.raw("ALTER TABLE bikes DROP CONSTRAINT IF EXISTS bikes_vehicle_type_check");
  await knex("bikes").where({vehicle_type: "bike"}).update({vehicle_type: "scooter"});
  await knex.raw("ALTER TABLE bikes ALTER COLUMN vehicle_type SET DEFAULT 'scooter'");
  await knex.raw(`
    ALTER TABLE bikes
    ADD CONSTRAINT bikes_vehicle_type_check
    CHECK (vehicle_type IN ('scooter', 'car'))
  `);
};

exports.down = async function (knex) {
  await knex.raw("ALTER TABLE bikes DROP CONSTRAINT IF EXISTS bikes_vehicle_type_check");
  await knex("bikes").where({vehicle_type: "scooter"}).update({vehicle_type: "bike"});
  await knex.raw("ALTER TABLE bikes ALTER COLUMN vehicle_type SET DEFAULT 'bike'");
  await knex.raw(`
    ALTER TABLE bikes
    ADD CONSTRAINT bikes_vehicle_type_check
    CHECK (vehicle_type IN ('bike', 'car'))
  `);
};
