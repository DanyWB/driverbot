<?php

namespace Tests\Feature;

use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Enums\PricingSource;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BookingPriceSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_calculation_and_manual_override_create_separate_versions_and_audit(): void
    {
        $booking = Booking::factory()->create();
        $admin = User::factory()->create();
        $quote = new PriceQuote(
            vehicleId: $booking->vehicle_id,
            startsOn: '2026-08-01',
            endsOn: '2026-08-07',
            totalDays: 7,
            tier: PricingTier::SevenDays,
            calculatedTotal: '3500.000000',
            roundedTotal: 3500,
            finalTotal: 3500,
            currency: 'THB',
            breakdown: [],
        );
        $service = app(BookingPriceSnapshotService::class);

        $automatic = $service->createAutomatic($booking, $quote);
        $manual = $service->override($booking, 3200, 'Long-term customer discount', $admin);

        $this->assertSame(1, $automatic->version);
        $this->assertSame(PricingSource::Automatic, $automatic->pricing_source);
        $this->assertSame(3500, $automatic->fresh()->final_total);
        $this->assertSame(2, $manual->version);
        $this->assertSame(PricingSource::ManualOverride, $manual->pricing_source);
        $this->assertSame(3200, $manual->final_total);
        $this->assertSame('Long-term customer discount', $manual->override_reason);
        $this->assertSame(2, BookingPriceSnapshot::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(2, AuditLog::query()->where('subject_id', $booking->public_id)->count());
    }

    public function test_manual_override_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(BookingPriceSnapshotService::class)->override(
            Booking::factory()->create(),
            1000,
            ' ',
            User::factory()->create(),
        );
    }
}
