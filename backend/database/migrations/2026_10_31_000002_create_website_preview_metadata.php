<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_preview_metadata', function (Blueprint $table): void {
            $table->string('instance_id', 32)->primary();
            $table->string('marker');
            $table->string('snapshot_sha256', 64);
            $table->string('pricing_source_sha256', 64);
            $table->string('mapping_sha256', 64);
            $table->string('media_manifest_sha256', 64);
            $table->unsignedInteger('vehicle_count');
            $table->unsignedInteger('photo_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_preview_metadata');
    }
};
