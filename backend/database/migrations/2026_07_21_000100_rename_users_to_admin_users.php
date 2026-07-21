<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'admin_users');

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->string('role', 32)->default('full_admin');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_login_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('full_admin'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check');
        }

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });

        Schema::rename('admin_users', 'users');
    }
};
