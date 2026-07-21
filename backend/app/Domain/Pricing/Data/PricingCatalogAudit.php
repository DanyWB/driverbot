<?php

namespace App\Domain\Pricing\Data;

final readonly class PricingCatalogAudit
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $vehicles,
        public int $activeVehicles,
        public int $visibleVehicles,
        public int $completePriceMatrices,
        public int $incompletePriceMatrices,
        public int $priceRows,
        public array $errors,
        public array $warnings,
    ) {}

    public function passed(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'vehicles' => $this->vehicles,
            'active_vehicles' => $this->activeVehicles,
            'visible_vehicles' => $this->visibleVehicles,
            'complete_price_matrices' => $this->completePriceMatrices,
            'incomplete_price_matrices' => $this->incompletePriceMatrices,
            'price_rows' => $this->priceRows,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
