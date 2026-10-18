<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('minimum_days')->default(1);
            $table->json('category_minimum_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_policies');
    }
};
