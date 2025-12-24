exports.up = function (knex) {
  return knex.schema.createTable("reminders", function (table) {
    table.increments("id").primary();
    table.integer("rental_id").references("id").inTable("rentals").onDelete("CASCADE");
    table.string("type").notNullable(); // start_24h, start_1h, end_24h, end_1h
    table.timestamp("send_at").notNullable();
    table.boolean("sent").notNullable().defaultTo(false);
    table.timestamp("created_at").defaultTo(knex.fn.now());
  });
};

exports.down = function (knex) {
  return knex.schema.dropTable("reminders");
};
