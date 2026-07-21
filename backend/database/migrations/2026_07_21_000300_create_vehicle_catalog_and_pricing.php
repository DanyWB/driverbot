<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('vehicle_type', 16)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['vehicle_type', 'is_active', 'sort_order']);
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('external_code', 160)->unique();
            $table->string('type', 16);
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('inventory_code', 100)->nullable()->unique();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('description')->nullable();
            $table->text('characteristics_text')->nullable();
            $table->string('emoji', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible_for_booking')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('pricing_profile', 64)->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'is_active', 'is_visible_for_booking'], 'vehicles_catalog_visibility_index');
            $table->index(['category_id', 'sort_order']);
        });

        Schema::create('vehicle_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 64)->default('public');
            $table->string('file_path');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['vehicle_id', 'file_path']);
            $table->index(['vehicle_id', 'is_primary', 'sort_order']);
        });

        Schema::create('pricing_seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('pricing_season_months', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('month')->unique();

            $table->unique(['pricing_season_id', 'month']);
        });

        Schema::create('vehicle_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_season_id')->constrained()->restrictOnDelete();
            $table->string('tier_key', 16);
            $table->unsignedSmallInteger('min_days');
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->unsignedSmallInteger('anchor_days');
            $table->unsignedBigInteger('package_total');
            $table->decimal('daily_rate', 14, 6);
            $table->char('currency', 3)->default('THB');
            $table->boolean('is_active')->default(true);
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestampsTz();

            $table->unique(['vehicle_id', 'pricing_season_id', 'tier_key'], 'vehicle_season_tier_unique');
            $table->index(['vehicle_id', 'tier_key', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_vehicle_type_check CHECK (vehicle_type IS NULL OR vehicle_type IN ('bike', 'scooter', 'car'))");
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check CHECK (type IN ('bike', 'scooter', 'car'))");
            DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_year_check CHECK (year IS NULL OR year BETWEEN 1900 AND 2200)');
            DB::statement('ALTER TABLE pricing_season_months ADD CONSTRAINT pricing_season_month_check CHECK (month BETWEEN 1 AND 12)');
            DB::statement("ALTER TABLE vehicle_price_tiers ADD CONSTRAINT vehicle_price_tiers_key_check CHECK (tier_key IN ('1d', '7d', '14d', '21d', 'month'))");
            DB::statement('ALTER TABLE vehicle_price_tiers ADD CONSTRAINT vehicle_price_tiers_days_check CHECK (min_days >= 1 AND anchor_days >= 1 AND (max_days IS NULL OR max_days >= min_days))');
            DB::statement('ALTER TABLE vehicle_price_tiers ADD CONSTRAINT vehicle_price_tiers_amount_check CHECK (package_total >= 0 AND daily_rate >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_price_tiers');
        Schema::dropIfExists('pricing_season_months');
        Schema::dropIfExists('pricing_seasons');
        Schema::dropIfExists('vehicle_photos');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('categories');
    }
};
