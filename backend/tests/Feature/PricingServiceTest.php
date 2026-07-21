<?php

namespace Tests\Feature;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\PricingSeason;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
    }

    public function test_one_day_is_inclusive_and_rounded_half_up_to_one_hundred_baht(): void
    {
        $this->price(PricingSeasonKey::High, PricingTier::OneDay, 250);

        $quote = app(PricingService::class)->quote(
            $this->vehicle,
            RentalPeriod::fromStrings('2026-12-10', '2026-12-10'),
        );

        $this->assertSame(1, $quote->totalDays);
        $this->assertSame(PricingTier::OneDay, $quote->tier);
        $this->assertSame('250.000000', $quote->calculatedTotal);
        $this->assertSame(300, $quote->roundedTotal);
    }

    public function test_thirty_day_cross_season_quote_uses_month_rates_for_every_day(): void
    {
        $this->price(PricingSeasonKey::High, PricingTier::Month, 4400);
        $this->price(PricingSeasonKey::Middle, PricingTier::Month, 3900);

        $quote = app(PricingService::class)->quote(
            $this->vehicle,
            RentalPeriod::fromStrings('2027-03-17', '2027-04-15'),
        );

        $this->assertSame(30, $quote->totalDays);
        $this->assertSame(PricingTier::Month, $quote->tier);
        $this->assertSame('4150.000000', $quote->calculatedTotal);
        $this->assertSame(4200, $quote->roundedTotal);
        $this->assertSame([
            ['season' => 'high', 'days' => 15],
            ['season' => 'middle', 'days' => 15],
        ], array_map(
            fn ($item): array => ['season' => $item->season, 'days' => $item->days],
            $quote->breakdown,
        ));
    }

    public function test_missing_price_has_a_stable_business_error_code(): void
    {
        try {
            app(PricingService::class)->quote(
                $this->vehicle,
                RentalPeriod::fromStrings('2026-06-01', '2026-06-07'),
            );
            $this->fail('A quote without a price was accepted.');
        } catch (PricingException $exception) {
            $this->assertSame('price_missing', $exception->errorCode);
            $this->assertSame('low', $exception->context['season']);
            $this->assertSame('7d', $exception->context['tier']);
        }
    }

    public function test_hidden_vehicle_is_rejected_by_default_but_can_be_previewed_by_admin(): void
    {
        $this->price(PricingSeasonKey::Low, PricingTier::OneDay, 300);
        $this->vehicle->update(['is_visible_for_booking' => false]);

        try {
            app(PricingService::class)->quote(
                $this->vehicle->fresh(),
                RentalPeriod::fromStrings('2026-06-01', '2026-06-01'),
            );
            $this->fail('A hidden vehicle was accepted for a customer quote.');
        } catch (PricingException $exception) {
            $this->assertSame('vehicle_hidden', $exception->errorCode);
        }

        $quote = app(PricingService::class)->quote(
            $this->vehicle->fresh(),
            RentalPeriod::fromStrings('2026-06-01', '2026-06-01'),
            requireBookable: false,
        );

        $this->assertSame(300, $quote->finalTotal);
    }

    private function price(PricingSeasonKey $seasonKey, PricingTier $tier, int $packageTotal): void
    {
        $season = PricingSeason::query()->where('key', $seasonKey->value)->firstOrFail();

        VehiclePriceTier::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'pricing_season_id' => $season->id,
            'tier_key' => $tier,
            'min_days' => $tier->minimumDays(),
            'max_days' => $tier->maximumDays(),
            'anchor_days' => $tier->anchorDays(),
            'package_total' => $packageTotal,
            'daily_rate' => (string) BigDecimal::of($packageTotal)->dividedBy(
                $tier->anchorDays(),
                6,
                RoundingMode::HalfUp,
            ),
            'currency' => 'THB',
            'is_active' => true,
        ]);
    }
}
