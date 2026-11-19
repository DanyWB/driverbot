<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_events', function (Blueprint $table): void {
            // Set only when a processor explicitly confirms remote state, not on local simulation.
            $table->timestampTz('last_remote_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_events', function (Blueprint $table): void {
            $table->dropColumn('last_remote_verified_at');
        });
    }
};
