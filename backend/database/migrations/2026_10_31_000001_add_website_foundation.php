<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('engine_cc')->nullable();
            $table->string('color', 100)->nullable();
            $table->boolean('abs')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('transmission', 30)->nullable();
        });
        Schema::create('website_booking_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('owner_hash', 64)->index();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->string('contact_channel', 30);
            $table->timestamps();
            $table->unique(['owner_hash', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_booking_requests');
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['brand', 'model', 'engine_cc', 'color', 'abs', 'seats', 'transmission']));
    }
};
