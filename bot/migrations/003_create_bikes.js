
exports.up = function(knex) {
  return knex.schema.createTable('bikes', function(table) {
    table.increments('id').primary();
    table.string('bike_id').unique().notNullable();
    table.string('name').notNullable();
    table.integer('category_id').references('id').inTable('categories');
    table.text('description');
    table.jsonb('meta').defaultTo('{}');
  });
};

exports.down = function(knex) {
  return knex.schema.dropTable('bikes');
};
