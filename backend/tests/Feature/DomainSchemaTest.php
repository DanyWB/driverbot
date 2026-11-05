<?php

namespace Tests\Feature;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\PricingSeasonMonth;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_two_tables_and_reference_seasons_exist(): void
    {
        $tables = [
            'admin_users',
            'customers',
            'customer_contacts',
            'customer_identities',
            'categories',
            'vehicles',
            'vehicle_photos',
            'pricing_seasons',
            'pricing_season_months',
            'vehicle_price_tiers',
            'bookings',
            'booking_price_snapshots',
            'booking_status_history',
            'google_calendar_events',
            'google_calendar_outbox',
            'google_calendar_reconciliations',
            'vehicle_occupancies',
            'customer_documents',
            'audit_logs',
            'notification_outbox',
            'admin_telegram_bindings',
            'admin_telegram_binding_codes',
            'service_api_clients',
            'idempotency_keys',
            'data_import_runs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->seed();

        $this->assertSame(range(1, 12), PricingSeasonMonth::query()->orderBy('month')->pluck('month')->all());
    }

    public function test_booking_relations_casts_and_public_id_are_usable(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Process,
            'source' => BookingSource::AdminPhone,
        ]);

        $this->assertNotEmpty($booking->public_id);
        $this->assertInstanceOf(Customer::class, $booking->customer);
        $this->assertInstanceOf(Vehicle::class, $booking->vehicle);
        $this->assertSame(BookingStatus::Process, $booking->status);
        $this->assertSame(BookingSource::AdminPhone, $booking->source);
    }

    public function test_google_calendar_sync_schema_supports_revisioned_independent_events(): void
    {
        foreach (['google_calendar_events', 'google_calendar_outbox', 'google_calendar_reconciliations'] as $table) {
            $this->assertSame(
                Schema::getColumnType('bookings', 'id'),
                Schema::getColumnType($table, 'booking_id'),
            );
            $foreignKey = collect(Schema::getForeignKeys($table))
                ->first(fn (array $key): bool => $key['columns'] === ['booking_id']);
            $this->assertNotNull($foreignKey, "Missing booking foreign key on {$table}.");
            $this->assertSame('bookings', $foreignKey['foreign_table']);
            $this->assertSame('restrict', strtolower((string) $foreignKey['on_delete']));
        }

        $eventColumns = [
            'booking_id', 'event_type', 'calendar_id', 'google_event_id', 'sync_status',
            'last_requested_revision', 'last_applied_revision', 'sync_lease_token',
            'sync_locked_until', 'last_error', 'last_synced_at', 'last_remote_verified_at', 'created_at', 'updated_at',
        ];
        $this->assertTrue(Schema::hasColumns('google_calendar_events', $eventColumns));
        $this->assertTrue(Schema::hasColumns('google_calendar_outbox', [
            'booking_id', 'event_type', 'operation', 'event_revision', 'idempotency_key',
            'status', 'retry_count', 'available_at', 'lease_token', 'locked_until',
            'last_error', 'processed_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('google_calendar_reconciliations', [
            'booking_id', 'event_type', 'requested_generation', 'completed_generation', 'claimed_generation',
            'available_at', 'lease_token', 'locked_until', 'retry_count', 'unexpected_event_id',
            'last_error', 'uncertain_since', 'last_convergence_at', 'convergence_checks',
            'convergence_failures', 'manual_review_required', 'created_at', 'updated_at',
        ]));

        $this->assertTrue($this->hasUniqueIndex('google_calendar_events', ['booking_id', 'event_type']));
        $this->assertTrue($this->hasUniqueIndex('google_calendar_outbox', ['idempotency_key']));
        $this->assertTrue($this->hasUniqueIndex('google_calendar_outbox', [
            'booking_id', 'event_type', 'event_revision',
        ]));
        $this->assertTrue($this->hasUniqueIndex('google_calendar_reconciliations', ['booking_id', 'event_type']));
        $this->assertTrue($this->hasIndex('google_calendar_reconciliations', [
            'requested_generation', 'completed_generation', 'available_at',
        ]));
        $this->assertTrue($this->hasIndex('google_calendar_reconciliations', ['uncertain_since', 'available_at']));
        $this->assertTrue($this->hasIndex('google_calendar_outbox', ['status', 'available_at', 'id']));
        $this->assertTrue($this->hasIndex('google_calendar_outbox', ['status', 'locked_until']));
    }

    public function test_price_snapshots_cannot_be_changed_after_creation(): void
    {
        $snapshot = BookingPriceSnapshot::query()->create([
            'booking_id' => Booking::factory()->create()->id,
            'version' => 1,
            'total_days' => 7,
            'tier_key' => PricingTier::SevenDays,
            'calculated_total' => '3500.000000',
            'rounded_total' => 3500,
            'final_total' => 3500,
            'currency' => 'THB',
            'breakdown' => ['days' => 7],
            'pricing_source' => 'automatic',
            'calculated_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        $snapshot->update(['final_total' => 3600]);
    }

    public function test_sensitive_fields_are_hidden_from_default_serialization(): void
    {
        $customer = Customer::factory()->create(['internal_note' => 'Private note']);
        $document = CustomerDocument::query()->create([
            'customer_id' => $customer->id,
            'type' => 'passport',
            'disk' => 'private',
            'file_path' => 'documents/secret.pdf',
            'original_filename' => 'passport.pdf',
            'uploaded_by' => 'customer',
        ]);

        $this->assertArrayNotHasKey('internal_note', $customer->toArray());
        $this->assertArrayNotHasKey('disk', $document->toArray());
        $this->assertArrayNotHasKey('file_path', $document->toArray());
    }

    /** @param list<string> $columns */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $columns */
    private function hasIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }
}
