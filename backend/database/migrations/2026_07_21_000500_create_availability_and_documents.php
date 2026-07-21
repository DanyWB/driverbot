<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('blocks_availability')->default(true);
            $table->string('label')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique('booking_id');
            $table->index(['vehicle_id', 'starts_on', 'ends_on']);
            $table->index(['type', 'blocks_availability']);
        });

        Schema::create('customer_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('disk', 64)->default('private');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('uploaded_by', 16);
            $table->foreignId('uploaded_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['disk', 'file_path']);
            $table->index(['customer_id', 'type']);
            $table->index('booking_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE vehicle_occupancies ADD CONSTRAINT vehicle_occupancies_type_check CHECK (type IN ('booking', 'maintenance'))");
            DB::statement('ALTER TABLE vehicle_occupancies ADD CONSTRAINT vehicle_occupancies_dates_check CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE vehicle_occupancies ADD CONSTRAINT vehicle_occupancies_source_check CHECK ((type = 'booking' AND booking_id IS NOT NULL) OR (type = 'maintenance' AND booking_id IS NULL))");
            DB::statement(<<<'SQL'
                ALTER TABLE vehicle_occupancies
                ADD CONSTRAINT vehicle_occupancies_no_overlap
                EXCLUDE USING gist (
                    vehicle_id WITH =,
                    daterange(starts_on, ends_on + 1, '[)') WITH &&
                ) WHERE (blocks_availability)
            SQL);
            DB::statement("ALTER TABLE customer_documents ADD CONSTRAINT customer_documents_type_check CHECK (type IN ('passport', 'driver_license', 'photo', 'other'))");
            DB::statement("ALTER TABLE customer_documents ADD CONSTRAINT customer_documents_uploader_check CHECK (uploaded_by IN ('customer', 'admin'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('vehicle_occupancies');
    }
};
