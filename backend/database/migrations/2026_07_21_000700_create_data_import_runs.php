<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 32);
            $table->string('source_filename');
            $table->char('source_sha256', 64)->index();
            $table->string('status', 16)->default('running');
            $table->json('summary')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['type', 'status', 'created_at']);
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignId('source_import_run_id')
                ->nullable()
                ->after('source_row')
                ->constrained('data_import_runs')
                ->nullOnDelete();
        });

        Schema::table('vehicle_price_tiers', function (Blueprint $table): void {
            $table->foreignId('source_import_run_id')
                ->nullable()
                ->after('source_row')
                ->constrained('data_import_runs')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE data_import_runs ADD CONSTRAINT data_import_runs_type_check CHECK (type IN ('vehicle_pricing'))");
            DB::statement("ALTER TABLE data_import_runs ADD CONSTRAINT data_import_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_price_tiers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_import_run_id');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_import_run_id');
        });

        Schema::dropIfExists('data_import_runs');
    }
};
