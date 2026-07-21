exports.up = function (knex) {
  return knex.schema.alterTable("categories", function (table) {
    table.string("description");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("categories", function (table) {
    table.dropColumn("description");
  });
};
