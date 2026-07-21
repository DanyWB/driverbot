exports.up = function (knex) {
  return knex.schema.createTable("seasons", function (table) {
    table.increments("id").primary();
    table.string("name").notNullable().unique(); // Low / Middle / High
    table.specificType("months", "integer[]"); // массив чисел от 1 до 12
  });
};

exports.down = function (knex) {
  return knex.schema.dropTable("seasons");
};
