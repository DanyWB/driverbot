<?php

namespace Tests\Unit;

use App\Domain\Pricing\ValueObjects\RentalPeriod;
use PHPUnit\Framework\TestCase;

class RentalPeriodTest extends TestCase
{
    public function test_return_date_is_not_an_extra_paid_day(): void
    {
        $period = RentalPeriod::fromStrings('2026-08-20', '2026-08-27');
        $this->assertSame(7, $period->totalDays());
        $days = iterator_to_array($period->dates());
        $this->assertCount(7, $days);
        $this->assertSame('2026-08-26', $days[6]->format('Y-m-d'));
    }

    public function test_same_day_has_a_one_day_floor_and_cross_year_is_supported(): void
    {
        $this->assertSame(1, RentalPeriod::fromStrings('2026-08-20', '2026-08-20')->totalDays());
        $this->assertSame(3, RentalPeriod::fromStrings('2026-12-30', '2027-01-02')->totalDays());
    }
}
