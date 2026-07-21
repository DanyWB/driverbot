exports.up = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.boolean("deposit_paid").notNullable().defaultTo(false);
    table.integer("deposit_required");
    table.string("contract_file_id");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.dropColumn("deposit_paid");
    table.dropColumn("deposit_required");
    table.dropColumn("contract_file_id");
  });
};
