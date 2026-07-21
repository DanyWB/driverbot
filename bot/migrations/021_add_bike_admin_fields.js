exports.up = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.boolean("is_active").notNullable().defaultTo(true);
    table.string("pricing_profile");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("bikes", function (table) {
    table.dropColumn("pricing_profile");
    table.dropColumn("is_active");
  });
};
