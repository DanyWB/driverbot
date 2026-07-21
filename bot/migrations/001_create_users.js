
exports.up = function(knex) {
  return knex.schema.createTable('users', function(table) {
    table.increments('id').primary();
    table.bigInteger('telegram_id').unique().notNullable();
    table.string('telegram_name');
    table.string('name');
    table.string('phone');
    table.string('passport_photo_file_id');
    table.boolean('is_admin').defaultTo(false);
    table.jsonb('meta').defaultTo('{}');
    table.timestamp('created_at').defaultTo(knex.fn.now());
  });
};

exports.down = function(knex) {
  return knex.schema.dropTable('users');
};
