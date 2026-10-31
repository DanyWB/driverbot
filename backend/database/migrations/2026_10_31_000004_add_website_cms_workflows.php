<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_website_profiles', fn (Blueprint $table) => $table->json('published_cover_manifest')->nullable());
        $contentColumns = static function (Blueprint $table): void {
            $table->json('draft_content');
            $table->json('published_content')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('published_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
        };
        Schema::create('website_pages', function (Blueprint $table) use ($contentColumns): void {
            $table->id();
            $table->string('key', 32)->unique();
            $contentColumns($table);
        });
        Schema::create('website_site_settings', function (Blueprint $table) use ($contentColumns): void {
            $table->unsignedTinyInteger('id')->primary();
            $contentColumns($table);
        });
        Schema::create('website_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('content');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['website_page_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_page_revisions');
        Schema::dropIfExists('website_site_settings');
        Schema::dropIfExists('website_pages');
        Schema::table('vehicle_website_profiles', fn (Blueprint $table) => $table->dropColumn('published_cover_manifest'));
    }
};
