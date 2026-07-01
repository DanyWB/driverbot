const RENTAL_STATUSES = [
  "process",
  "pending",
  "approved",
  "active",
  "ready",
  "completed",
  "finished",
  "expired",
  "returned",
  "cancelled",
  "cancelled_by_client",
];

const statusCheckValues = RENTAL_STATUSES.map((status) => `'${status}'`).join(", ");

exports.up = async function (knex) {
  await knex.raw(`
    CREATE UNIQUE INDEX IF NOT EXISTS rentals_booking_public_id_unique
    ON rentals (booking_public_id)
    WHERE booking_public_id IS NOT NULL
  `);

  await knex.raw(`
    CREATE UNIQUE INDEX IF NOT EXISTS bike_prices_unique_bike_season_days
    ON bike_prices (bike_id, season_id, days_type)
  `);

  await knex.raw(`
    CREATE INDEX IF NOT EXISTS rentals_bike_status_dates_idx
    ON rentals (bike_id, status, start_date, end_date)
  `);

  await knex.raw(`
    CREATE INDEX IF NOT EXISTS rentals_bike_status_times_idx
    ON rentals (bike_id, status, start_at, end_at)
  `);

  await knex.raw(`
    CREATE INDEX IF NOT EXISTS rentals_user_status_idx
    ON rentals (user_id, status)
  `);

  await knex.raw(`
    DO $$
    BEGIN
      IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'rentals_status_check'
      ) THEN
        ALTER TABLE rentals
        ADD CONSTRAINT rentals_status_check
        CHECK (status IN (${statusCheckValues}));
      END IF;
    END $$;
  `);
};

exports.down = async function (knex) {
  await knex.raw("ALTER TABLE rentals DROP CONSTRAINT IF EXISTS rentals_status_check");
  await knex.raw("DROP INDEX IF EXISTS rentals_user_status_idx");
  await knex.raw("DROP INDEX IF EXISTS rentals_bike_status_times_idx");
  await knex.raw("DROP INDEX IF EXISTS rentals_bike_status_dates_idx");
  await knex.raw("DROP INDEX IF EXISTS bike_prices_unique_bike_season_days");
  await knex.raw("DROP INDEX IF EXISTS rentals_booking_public_id_unique");
};
