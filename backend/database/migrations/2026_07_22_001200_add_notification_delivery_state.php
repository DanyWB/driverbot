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
            $table->unsignedInteger('reminder_version')->default(0)->after('pending_expires_at');
        });

        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->timestampTz('processing_started_at')->nullable()->after('available_at');
            $table->timestampTz('failed_at')->nullable()->after('sent_at');
            $table->timestampTz('discarded_at')->nullable()->after('failed_at');
            $table->string('provider_message_id', 128)->nullable()->after('discarded_at');
            $table->index(['status', 'processing_started_at'], 'notification_outbox_processing_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notification_outbox DROP CONSTRAINT notification_outbox_status_check');
            DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_status_check CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'discarded'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE notification_outbox SET status = 'failed', failed_at = COALESCE(failed_at, NOW()) WHERE status = 'discarded'");
            DB::statement('ALTER TABLE notification_outbox DROP CONSTRAINT notification_outbox_status_check');
            DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_status_check CHECK (status IN ('pending', 'processing', 'sent', 'failed'))");
        }

        Schema::table('notification_outbox', function (Blueprint $table): void {
            $table->dropIndex('notification_outbox_processing_index');
            $table->dropColumn(['processing_started_at', 'failed_at', 'discarded_at', 'provider_message_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('reminder_version');
        });
    }
};
