<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 16);
            $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('actor_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('actor_service_client_id')->nullable()->constrained('service_api_clients')->nullOnDelete();
            $table->string('subject_type');
            $table->string('subject_id', 128);
            $table->string('action', 100);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->uuid('request_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_logs_subject_index');
            $table->index(['actor_type', 'created_at']);
            $table->index('request_id');
        });

        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('deduplication_key')->unique();
            $table->string('event_type', 100);
            $table->string('channel', 32);
            $table->string('recipient');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_api_client_id')->nullable()->constrained('service_api_clients')->cascadeOnDelete();
            $table->string('scope', 100);
            $table->string('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['scope', 'idempotency_key']);
            $table->index(['expires_at', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_check CHECK (actor_type IN ('system', 'admin', 'customer', 'service'))");
            DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_channel_check CHECK (channel IN ('telegram', 'email', 'sms', 'internal'))");
            DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_status_check CHECK (status IN ('pending', 'processing', 'sent', 'failed'))");
            DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('processing', 'completed', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('service_api_clients');
    }
};
