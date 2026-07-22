<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('private_data')->nullable()->after('internal_note');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('helmets_quantity')->default(0)->after('deposit_note');
            $table->boolean('delivery_required')->default(false)->after('helmets_quantity');
            $table->text('delivery_address')->nullable()->after('delivery_required');
            $table->timestampTz('terms_accepted_at')->nullable()->after('delivery_address');
            $table->string('terms_version', 64)->nullable()->after('terms_accepted_at');
        });

        Schema::table('booking_status_history', function (Blueprint $table): void {
            $table->foreignId('actor_service_client_id')
                ->nullable()
                ->after('actor_customer_id')
                ->constrained('service_api_clients')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_helmets_quantity_check CHECK (helmets_quantity <= 4)');
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_terms_pair_check CHECK ((terms_accepted_at IS NULL AND terms_version IS NULL) OR (terms_accepted_at IS NOT NULL AND terms_version IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_helmets_quantity_check');
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_terms_pair_check');
        }

        Schema::table('booking_status_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_service_client_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'helmets_quantity',
                'delivery_required',
                'delivery_address',
                'terms_accepted_at',
                'terms_version',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('private_data');
        });
    }
};
