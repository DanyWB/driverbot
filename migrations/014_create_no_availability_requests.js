exports.up = function (knex) {
  return knex.schema.createTable("no_availability_requests", function (table) {
    table.increments("id").primary();
    table.integer("user_id").references("id").inTable("users");
    table.date("start_date").notNullable();
    table.date("end_date").notNullable();
    table.integer("category_id");
    table.text("comment");
    table.timestamp("created_at").defaultTo(knex.fn.now());
  });
};

exports.down = function (knex) {
  return knex.schema.dropTable("no_availability_requests");
};
