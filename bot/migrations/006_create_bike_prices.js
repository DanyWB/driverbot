
exports.up = function(knex) {
  return knex.schema.createTable('bike_prices', function(table) {
    table.increments('id').primary();
    table.integer('bike_id').references('id').inTable('bikes');
    table.integer('season_id').references('id').inTable('seasons');
    table.string('days_type');
    table.integer('price_per_day');
  });
};

exports.down = function(knex) {
  return knex.schema.dropTable('bike_prices');
};
