<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 16);
            $table->unsignedBigInteger('requested_generation')->default(1);
            $table->unsignedBigInteger('completed_generation')->default(0);
            $table->unsignedBigInteger('claimed_generation')->nullable();
            $table->timestampTz('available_at')->useCurrent();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('unexpected_event_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['booking_id', 'event_type'], 'google_calendar_reconciliations_booking_type_unique');
            $table->index(['requested_generation', 'completed_generation', 'available_at'], 'google_calendar_reconciliations_ready_index');
            $table->index(['lease_token', 'locked_until'], 'google_calendar_reconciliations_lease_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE google_calendar_reconciliations ADD CONSTRAINT google_calendar_reconciliations_type_check CHECK (event_type IN ('pickup', 'return'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_reconciliations');
    }
};
