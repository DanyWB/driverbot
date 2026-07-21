<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('pickup_time')->nullable();
            $table->time('return_time')->nullable();
            $table->string('status', 32)->default('process');
            $table->string('source', 32);
            $table->text('client_comment')->nullable();
            $table->text('admin_note')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('no_show_reason')->nullable();
            $table->text('deposit_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('pending_expires_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->timestampsTz();

            $table->index(['vehicle_id', 'starts_on', 'ends_on']);
            $table->index(['status', 'starts_on']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('booking_price_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('total_days');
            $table->string('tier_key', 16);
            $table->decimal('calculated_total', 14, 6);
            $table->unsignedBigInteger('rounded_total');
            $table->unsignedBigInteger('manual_total')->nullable();
            $table->unsignedBigInteger('final_total');
            $table->char('currency', 3)->default('THB');
            $table->json('breakdown');
            $table->string('pricing_source', 32)->default('automatic');
            $table->foreignId('overridden_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(['booking_id', 'version']);
            $table->index(['booking_id', 'created_at']);
        });

        Schema::create('booking_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 16);
            $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('actor_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['booking_id', 'created_at']);
            $table->index('request_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('process', 'pending', 'approved', 'active', 'completed', 'cancelled', 'cancelled_by_client', 'expired', 'no_show'))");
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_source_check CHECK (source IN ('telegram', 'admin_phone', 'admin_whatsapp', 'admin_instagram', 'admin_manual', 'website'))");
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_dates_check CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE booking_price_snapshots ADD CONSTRAINT booking_price_snapshots_tier_check CHECK (tier_key IN ('1d', '7d', '14d', '21d', 'month'))");
            DB::statement("ALTER TABLE booking_price_snapshots ADD CONSTRAINT booking_price_snapshots_source_check CHECK (pricing_source IN ('automatic', 'manual_override'))");
            DB::statement('ALTER TABLE booking_price_snapshots ADD CONSTRAINT booking_price_snapshots_amount_check CHECK (total_days >= 1 AND calculated_total >= 0 AND rounded_total >= 0 AND (manual_total IS NULL OR manual_total >= 0) AND final_total >= 0)');
            DB::statement("ALTER TABLE booking_status_history ADD CONSTRAINT booking_status_history_actor_check CHECK (actor_type IN ('system', 'admin', 'customer', 'service'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_history');
        Schema::dropIfExists('booking_price_snapshots');
        Schema::dropIfExists('bookings');
    }
};
