<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_reconciliations', function (Blueprint $table): void {
            // This marker means a remote side effect may still arrive after a repair.
            // It is intentionally not cleared by a successful reconciliation.
            $table->timestampTz('uncertain_since')->nullable();
            $table->timestampTz('last_convergence_at')->nullable();
            $table->unsignedInteger('convergence_checks')->default(0);
            $table->unsignedInteger('convergence_failures')->default(0);
            $table->boolean('manual_review_required')->default(false);
            $table->index(['uncertain_since', 'available_at'], 'google_calendar_reconciliation_convergence_index');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_reconciliations', function (Blueprint $table): void {
            $table->dropIndex('google_calendar_reconciliation_convergence_index');
            $table->dropColumn([
                'uncertain_since',
                'last_convergence_at',
                'convergence_checks',
                'convergence_failures',
                'manual_review_required',
            ]);
        });
    }
};
