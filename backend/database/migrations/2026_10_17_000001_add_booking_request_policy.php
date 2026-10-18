<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Existing requests retain their original availability/expiry policy.
            $table->unsignedSmallInteger('request_policy_version')->default(1);
            $table->timestampTz('submitted_at')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_telegram')->nullable();
            $table->unsignedInteger('details_version')->default(1);
            foreach (['prepayment', 'deposit'] as $payment) {
                $table->decimal($payment.'_amount', 12, 2)->nullable();
                $table->string($payment.'_currency', 3)->nullable();
                $table->timestampTz($payment.'_received_at')->nullable();
                $table->foreignId($payment.'_received_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            }
            $table->index(['status', 'pending_expires_at']);
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('terms_version')->nullable();
            $table->timestampTz('terms_accepted_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_status_check');
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('process', 'pending', 'approved', 'active', 'completed', 'cancelled', 'cancelled_by_client', 'cancelled_unavailable', 'expired', 'no_show'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This additive migration contains booking history and financial data. Roll forward instead of deleting those fields.');
    }
};
