<?php

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Enums\PricingTier;

final readonly class PriceQuote
{
    /** @param list<PriceBreakdownItem> $breakdown */
    public function __construct(
        public int $vehicleId,
        public string $startsOn,
        public string $endsOn,
        public int $totalDays,
        public PricingTier $tier,
        public string $calculatedTotal,
        public int $roundedTotal,
        public int $finalTotal,
        public string $currency,
        public array $breakdown,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'start_date' => $this->startsOn,
            'end_date' => $this->endsOn,
            'total_days' => $this->totalDays,
            'tier_key' => $this->tier->value,
            'calculated_total' => $this->calculatedTotal,
            'rounded_total' => $this->roundedTotal,
            'final_total' => $this->finalTotal,
            'currency' => $this->currency,
            'breakdown' => array_map(
                fn (PriceBreakdownItem $item): array => $item->toArray(),
                $this->breakdown,
            ),
            'missing_prices' => [],
        ];
    }
}
