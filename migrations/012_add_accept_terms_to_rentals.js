exports.up = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.boolean("accept_terms").notNullable().defaultTo(false);
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.dropColumn("accept_terms");
  });
};
