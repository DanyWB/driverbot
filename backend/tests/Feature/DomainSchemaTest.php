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
            'vehicle_occupancies',
            'customer_documents',
            'audit_logs',
            'notification_outbox',
            'service_api_clients',
            'idempotency_keys',
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
            'status' => BookingStatus::Pending,
            'source' => BookingSource::AdminPhone,
        ]);

        $this->assertNotEmpty($booking->public_id);
        $this->assertInstanceOf(Customer::class, $booking->customer);
        $this->assertInstanceOf(Vehicle::class, $booking->vehicle);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingSource::AdminPhone, $booking->source);
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
}
