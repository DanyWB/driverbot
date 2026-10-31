<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_media_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('original_path');
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('original_bytes');
            $table->char('original_sha256', 64);
            $table->json('variants');
            $table->json('alt_text');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['vehicle_id', 'deleted_at']);
        });
        Schema::create('vehicle_website_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft_content');
            $table->foreignUuid('draft_cover_asset_id')->nullable()->constrained('website_media_assets')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->json('published_content')->nullable();
            $table->foreignUuid('published_cover_asset_id')->nullable()->constrained('website_media_assets')->restrictOnDelete();
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('published_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_website_profiles');
        Schema::dropIfExists('website_media_assets');
    }
};
