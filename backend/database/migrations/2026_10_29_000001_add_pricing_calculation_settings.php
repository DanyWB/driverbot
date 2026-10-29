<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_presets', function (Blueprint $table): void {
            $table->json('calculation_settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::table('vehicles', fn (Blueprint $table) => $table->json('calculation_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('calculation_settings'));
        Schema::table('pricing_presets', fn (Blueprint $table) => $table->dropColumn(['calculation_settings', 'sort_order']));
    }
};
