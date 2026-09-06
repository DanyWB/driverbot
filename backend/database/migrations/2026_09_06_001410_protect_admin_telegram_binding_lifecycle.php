<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresProtection();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteProtection();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS admin_tg_binding_scrub_delete ON admin_users');
            DB::unprepared('DROP TRIGGER IF EXISTS admin_tg_binding_scrub_update ON admin_users');
            DB::unprepared('DROP FUNCTION IF EXISTS scrub_admin_telegram_binding()');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS admin_tg_binding_scrub_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS admin_tg_binding_scrub_update');
        }
    }

    private function createPostgresProtection(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION scrub_admin_telegram_binding()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                should_scrub boolean;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    should_scrub := true;
                ELSE
                    should_scrub := NEW.is_active = false OR NEW.email_verified_at IS NULL;
                END IF;

                IF should_scrub THEN
                    UPDATE admin_telegram_binding_codes
                    SET revoked_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE admin_user_id = OLD.id
                      AND consumed_at IS NULL
                      AND revoked_at IS NULL;

                    UPDATE admin_telegram_bindings
                    SET telegram_user_id = NULL,
                        telegram_chat_id = NULL,
                        username = NULL,
                        first_name = NULL,
                        last_name = NULL,
                        locale = NULL,
                        generation = generation + 1,
                        disconnected_at = CURRENT_TIMESTAMP,
                        last_tested_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE admin_user_id = OLD.id
                      AND disconnected_at IS NULL
                      AND (
                          telegram_user_id IS NOT NULL
                          OR telegram_chat_id IS NOT NULL
                          OR connected_at IS NOT NULL
                      );
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER admin_tg_binding_scrub_delete
            BEFORE DELETE ON admin_users
            FOR EACH ROW
            EXECUTE FUNCTION scrub_admin_telegram_binding()
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER admin_tg_binding_scrub_update
            BEFORE UPDATE OF is_active, email_verified_at ON admin_users
            FOR EACH ROW
            EXECUTE FUNCTION scrub_admin_telegram_binding()
        SQL);
    }

    private function createSqliteProtection(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER admin_tg_binding_scrub_delete
            BEFORE DELETE ON admin_users
            FOR EACH ROW
            BEGIN
                UPDATE admin_telegram_binding_codes
                SET revoked_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE admin_user_id = OLD.id
                  AND consumed_at IS NULL
                  AND revoked_at IS NULL;

                UPDATE admin_telegram_bindings
                SET telegram_user_id = NULL,
                    telegram_chat_id = NULL,
                    username = NULL,
                    first_name = NULL,
                    last_name = NULL,
                    locale = NULL,
                    generation = generation + 1,
                    disconnected_at = CURRENT_TIMESTAMP,
                    last_tested_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE admin_user_id = OLD.id
                  AND disconnected_at IS NULL
                  AND (
                      telegram_user_id IS NOT NULL
                      OR telegram_chat_id IS NOT NULL
                      OR connected_at IS NOT NULL
                  );
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER admin_tg_binding_scrub_update
            BEFORE UPDATE OF is_active, email_verified_at ON admin_users
            FOR EACH ROW
            WHEN NEW.is_active = 0 OR NEW.email_verified_at IS NULL
            BEGIN
                UPDATE admin_telegram_binding_codes
                SET revoked_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE admin_user_id = OLD.id
                  AND consumed_at IS NULL
                  AND revoked_at IS NULL;

                UPDATE admin_telegram_bindings
                SET telegram_user_id = NULL,
                    telegram_chat_id = NULL,
                    username = NULL,
                    first_name = NULL,
                    last_name = NULL,
                    locale = NULL,
                    generation = generation + 1,
                    disconnected_at = CURRENT_TIMESTAMP,
                    last_tested_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE admin_user_id = OLD.id
                  AND disconnected_at IS NULL
                  AND (
                      telegram_user_id IS NOT NULL
                      OR telegram_chat_id IS NOT NULL
                      OR connected_at IS NOT NULL
                  );
            END
        SQL);
    }
};
