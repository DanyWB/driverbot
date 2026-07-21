exports.up = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.text("deposit_note");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.dropColumn("deposit_note");
  });
};
