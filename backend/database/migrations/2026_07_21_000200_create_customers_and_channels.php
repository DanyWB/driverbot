<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('locale', 8)->default('en');
            $table->text('internal_note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('customer_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('value');
            $table->string('normalized_value');
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['customer_id', 'type', 'normalized_value'], 'customer_contacts_identity_unique');
            $table->index(['type', 'normalized_value']);
        });

        Schema::create('customer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'external_id']);
            $table->index(['customer_id', 'provider']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customer_contacts ADD CONSTRAINT customer_contacts_type_check CHECK (type IN ('phone', 'email', 'whatsapp', 'telegram_username'))");
            DB::statement("ALTER TABLE customer_identities ADD CONSTRAINT customer_identities_provider_check CHECK (provider IN ('telegram', 'web'))");
            DB::statement('CREATE UNIQUE INDEX customer_contacts_one_primary_per_type ON customer_contacts (customer_id, type) WHERE is_primary');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
    }
};
