<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE bookings
                ADD CONSTRAINT bookings_delivery_address_check
                CHECK (NOT delivery_required OR NULLIF(BTRIM(delivery_address), '') IS NOT NULL)
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_delivery_address_check');
        }
    }
};
