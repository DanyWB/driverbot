<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('operation', 16);
            $table->unsignedBigInteger('event_revision');
            $table->string('idempotency_key')->unique();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['booking_id', 'event_type', 'event_revision'],
                'google_calendar_outbox_booking_event_revision_unique',
            );
            $table->index(['status', 'available_at', 'id'], 'google_calendar_outbox_ready_index');
            $table->index(['status', 'locked_until'], 'google_calendar_outbox_lease_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE google_calendar_outbox ADD CONSTRAINT google_calendar_outbox_type_check CHECK (event_type IN ('pickup', 'return'))");
            DB::statement("ALTER TABLE google_calendar_outbox ADD CONSTRAINT google_calendar_outbox_operation_check CHECK (operation IN ('upsert', 'delete'))");
            DB::statement("ALTER TABLE google_calendar_outbox ADD CONSTRAINT google_calendar_outbox_status_check CHECK (status IN ('pending', 'processing', 'processed', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_outbox');
    }
};
