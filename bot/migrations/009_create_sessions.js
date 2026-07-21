exports.up = function (knex) {
  return knex.schema.createTable("sessions", (table) => {
    table.bigInteger("user_id").primary();
    table.jsonb("data").notNullable();
  });
};

exports.down = function (knex) {
  return knex.schema.dropTableIfExists("sessions");
};
