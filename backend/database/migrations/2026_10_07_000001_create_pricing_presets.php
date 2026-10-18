<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('vehicle_type', 16)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['vehicle_type', 'name']);
        });

        Schema::create('pricing_preset_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_season_id')->constrained()->restrictOnDelete();
            $table->string('tier_key', 16);
            $table->unsignedBigInteger('package_total');
            $table->timestampsTz();

            $table->unique(
                ['pricing_preset_id', 'pricing_season_id', 'tier_key'],
                'pricing_preset_season_tier_unique',
            );
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('pricing_preset_id')
                ->nullable()
                ->after('pricing_profile')
                ->constrained('pricing_presets')
                ->restrictOnDelete();
            $table->unsignedInteger('pricing_version')->default(1)->after('pricing_preset_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE pricing_presets ADD CONSTRAINT pricing_presets_vehicle_type_check CHECK (vehicle_type IS NULL OR vehicle_type IN ('scooter', 'car'))");
            DB::statement('ALTER TABLE pricing_presets ADD CONSTRAINT pricing_presets_version_check CHECK (version >= 1)');
            DB::statement("ALTER TABLE pricing_preset_prices ADD CONSTRAINT pricing_preset_prices_tier_check CHECK (tier_key IN ('1d', '7d', '14d', '21d', 'month'))");
            DB::statement('ALTER TABLE pricing_preset_prices ADD CONSTRAINT pricing_preset_prices_amount_check CHECK (package_total >= 1)');
            DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_pricing_version_check CHECK (pricing_version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pricing_preset_id');
            $table->dropColumn('pricing_version');
        });

        Schema::dropIfExists('pricing_preset_prices');
        Schema::dropIfExists('pricing_presets');
    }
};
