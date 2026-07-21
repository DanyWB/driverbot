exports.up = function (knex) {
  return knex.schema.alterTable("bike_prices", function (table) {
    table.decimal("price_per_day", 10, 2).alter();
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("bike_prices", function (table) {
    table.integer("price_per_day").alter();
  });
};
