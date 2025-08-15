exports.up = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.dropColumn("bike_id");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.integer("bike_id");
  });
};
