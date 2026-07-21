exports.up = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.timestamp("start_at");
    table.timestamp("end_at");
    table.integer("helmets_qty").notNullable().defaultTo(0);
    table.boolean("delivery_required").notNullable().defaultTo(false);
    table.text("delivery_address");
    table.boolean("docs_missing").notNullable().defaultTo(false);
    table.string("booking_public_id");
    table.integer("deposit");
  });
};

exports.down = function (knex) {
  return knex.schema.alterTable("rentals", function (table) {
    table.dropColumn("start_at");
    table.dropColumn("end_at");
    table.dropColumn("helmets_qty");
    table.dropColumn("delivery_required");
    table.dropColumn("delivery_address");
    table.dropColumn("docs_missing");
    table.dropColumn("booking_public_id");
    table.dropColumn("deposit");
  });
};
