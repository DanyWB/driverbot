<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE booking_status_history ALTER COLUMN request_id TYPE VARCHAR(100) USING request_id::text');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN request_id TYPE VARCHAR(100) USING request_id::text');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE booking_status_history ALTER COLUMN request_id TYPE UUID USING request_id::uuid');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN request_id TYPE UUID USING request_id::uuid');
    }
};
