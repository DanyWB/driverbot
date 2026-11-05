<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('calendar_id', 255);
            $table->string('google_event_id', 255)->nullable();
            $table->string('sync_status', 24)->default('pending');
            $table->unsignedBigInteger('last_requested_revision')->default(0);
            $table->unsignedBigInteger('last_applied_revision')->default(0);
            $table->uuid('sync_lease_token')->nullable();
            $table->timestampTz('sync_locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['booking_id', 'event_type'], 'google_calendar_events_booking_type_unique');
            $table->index(['sync_status', 'updated_at'], 'google_calendar_events_status_updated_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE google_calendar_events ADD CONSTRAINT google_calendar_events_type_check CHECK (event_type IN ('pickup', 'return'))");
            DB::statement("ALTER TABLE google_calendar_events ADD CONSTRAINT google_calendar_events_status_check CHECK (sync_status IN ('pending', 'synced', 'failed', 'delete_pending', 'deleted'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_events');
    }
};
