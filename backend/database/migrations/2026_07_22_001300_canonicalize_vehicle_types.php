<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_vehicle_type_check');
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_type_check');
        }

        DB::table('categories')->where('vehicle_type', 'bike')->update(['vehicle_type' => 'scooter']);
        DB::table('vehicles')->where('type', 'bike')->update(['type' => 'scooter']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_vehicle_type_check CHECK (vehicle_type IS NULL OR vehicle_type IN ('scooter', 'car'))");
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check CHECK (type IN ('scooter', 'car'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_vehicle_type_check');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_type_check');
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_vehicle_type_check CHECK (vehicle_type IS NULL OR vehicle_type IN ('bike', 'scooter', 'car'))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check CHECK (type IN ('bike', 'scooter', 'car'))");
    }
};
