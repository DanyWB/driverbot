exports.up = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.timestamp("updated_at");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.dropColumn("updated_at");
  });
};
