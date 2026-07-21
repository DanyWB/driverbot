<?php

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Enums\PricingTier;

final readonly class PriceBreakdownItem
{
    public function __construct(
        public string $season,
        public int $days,
        public PricingTier $tier,
        public int $packageTotal,
        public int $anchorDays,
        public string $dailyRate,
        public string $subtotal,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'season' => $this->season,
            'days' => $this->days,
            'tier' => $this->tier->value,
            'package_total' => $this->packageTotal,
            'anchor_days' => $this->anchorDays,
            'daily_rate' => $this->dailyRate,
            'subtotal' => $this->subtotal,
        ];
    }
}
