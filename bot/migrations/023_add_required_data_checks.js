exports.up = async function (knex) {
  await knex.raw(`
    ALTER TABLE rentals
    ADD CONSTRAINT rentals_status_required_check
    CHECK (status IS NOT NULL)
  `);

  await knex.raw(`
    ALTER TABLE rentals
    ADD CONSTRAINT rentals_date_range_check
    CHECK (start_date <= end_date)
  `);

  await knex.raw(`
    ALTER TABLE rentals
    ADD CONSTRAINT rentals_time_range_check
    CHECK (
      (start_at IS NULL AND end_at IS NULL)
      OR (start_at IS NOT NULL AND end_at IS NOT NULL AND start_at < end_at)
    )
  `);

  await knex.raw(`
    ALTER TABLE rentals
    ADD CONSTRAINT rentals_blocking_public_id_check
    CHECK (
      status NOT IN ('pending', 'approved', 'active', 'ready')
      OR booking_public_id IS NOT NULL
    )
  `);

  await knex.raw(`
    ALTER TABLE bike_prices
    ADD CONSTRAINT bike_prices_required_fields_check
    CHECK (
      bike_id IS NOT NULL
      AND season_id IS NOT NULL
      AND days_type IS NOT NULL
      AND price_per_day IS NOT NULL
      AND price_per_day >= 0
    )
  `);
};

exports.down = async function (knex) {
  await knex.raw("ALTER TABLE bike_prices DROP CONSTRAINT IF EXISTS bike_prices_required_fields_check");
  await knex.raw("ALTER TABLE rentals DROP CONSTRAINT IF EXISTS rentals_blocking_public_id_check");
  await knex.raw("ALTER TABLE rentals DROP CONSTRAINT IF EXISTS rentals_time_range_check");
  await knex.raw("ALTER TABLE rentals DROP CONSTRAINT IF EXISTS rentals_date_range_check");
  await knex.raw("ALTER TABLE rentals DROP CONSTRAINT IF EXISTS rentals_status_required_check");
};
