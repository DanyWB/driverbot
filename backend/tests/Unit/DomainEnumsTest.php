<?php

namespace Tests\Unit;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Pricing\Enums\PricingTier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainEnumsTest extends TestCase
{
    /** @return array<string, array{int, PricingTier}> */
    public static function durationTierCases(): array
    {
        return [
            'one day' => [1, PricingTier::OneDay],
            'six days' => [6, PricingTier::OneDay],
            'seven days' => [7, PricingTier::SevenDays],
            'thirteen days' => [13, PricingTier::SevenDays],
            'fourteen days' => [14, PricingTier::FourteenDays],
            'twenty days' => [20, PricingTier::FourteenDays],
            'twenty-one days' => [21, PricingTier::TwentyOneDays],
            'twenty-nine days' => [29, PricingTier::TwentyOneDays],
            'thirty days' => [30, PricingTier::Month],
            'long rental' => [120, PricingTier::Month],
        ];
    }

    #[DataProvider('durationTierCases')]
    public function test_duration_selects_exactly_one_pricing_tier(int $days, PricingTier $expected): void
    {
        $this->assertSame($expected, PricingTier::forDuration($days));
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PricingTier::forDuration(0);
    }

    public function test_only_inventory_holding_statuses_block_availability(): void
    {
        $blocking = array_values(array_filter(
            BookingStatus::cases(),
            fn (BookingStatus $status): bool => $status->blocksAvailability(),
        ));

        $this->assertSame([
            BookingStatus::Pending,
            BookingStatus::Approved,
            BookingStatus::Active,
        ], $blocking);
        $this->assertTrue(BookingStatus::Approved->canBecomeNoShow());
        $this->assertFalse(BookingStatus::Active->canBecomeNoShow());
    }
}
