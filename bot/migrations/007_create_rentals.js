
exports.up = function(knex) {
  return knex.schema.createTable('rentals', function(table) {
    table.increments('id').primary();
    table.integer('user_id').references('id').inTable('users');
    table.integer('bike_id').references('id').inTable('bikes');
    table.date('start_date').notNullable();
    table.date('end_date').notNullable();
    table.integer('total_price');
    table.string('status').defaultTo('process');
    table.text('comment');
    table.timestamp('created_at').defaultTo(knex.fn.now());
    table.timestamp('confirmed_at');
  });
};

exports.down = function(knex) {
  return knex.schema.dropTable('rentals');
};
