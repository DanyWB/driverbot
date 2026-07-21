exports.up = function (knex) {
  return knex.schema.alterTable("users", function (table) {
    table.string("lang").defaultTo("ru");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("users", function (table) {
    table.dropColumn("lang");
  });
};
