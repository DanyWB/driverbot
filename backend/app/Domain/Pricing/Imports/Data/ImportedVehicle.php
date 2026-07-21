<?php

namespace App\Domain\Pricing\Imports\Data;

use App\Domain\Vehicles\Enums\VehicleType;

final readonly class ImportedVehicle
{
    /** @param list<ImportedPrice> $prices */
    public function __construct(
        public string $externalCode,
        public VehicleType $type,
        public string $categoryCode,
        public string $categoryName,
        public string $name,
        public ?string $inventoryCode,
        public ?int $year,
        public bool $isActive,
        public bool $hasExplicitActiveMarker,
        public string $sourceSheet,
        public int $sourceRow,
        public array $prices,
    ) {}

    public function hasCompletePriceMatrix(): bool
    {
        return count($this->prices) === 15;
    }
}
