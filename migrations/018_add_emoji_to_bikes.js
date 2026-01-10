exports.up = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.string("emoji");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.dropColumn("emoji");
  });
};
