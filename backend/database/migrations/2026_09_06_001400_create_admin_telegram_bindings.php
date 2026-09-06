<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_telegram_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')
                ->nullable()
                ->unique()
                ->constrained('admin_users')
                ->nullOnDelete();
            $table->string('telegram_user_id', 20)->nullable()->unique();
            $table->string('telegram_chat_id', 20)->nullable()->unique();
            $table->string('username', 64)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('locale', 8)->nullable();
            $table->unsignedInteger('generation')->default(0);
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['connected_at', 'disconnected_at'],
                'admin_tg_bindings_state_idx',
            );
        });

        Schema::create('admin_telegram_binding_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['admin_user_id', 'consumed_at', 'revoked_at', 'expires_at'],
                'admin_tg_codes_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_telegram_binding_codes');
        Schema::dropIfExists('admin_telegram_bindings');
    }
};
