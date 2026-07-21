<?php

namespace App\Domain\Pricing\Imports\Data;

final readonly class PricingImportReport
{
    /**
     * @param  list<string>  $inactiveVehicleCodes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $dryRun,
        public string $sourceFilename,
        public string $sourceSha256,
        public int $vehiclesTotal,
        public int $vehiclesCreated,
        public int $vehiclesUpdated,
        public int $vehiclesWithFullPrices,
        public int $vehiclesWithoutFullPrices,
        public int $activeVehicles,
        public int $inactiveVehicles,
        public array $inactiveVehicleCodes,
        public int $priceRows,
        public array $warnings,
        public ?string $importRunId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'source_filename' => $this->sourceFilename,
            'source_sha256' => $this->sourceSha256,
            'import_run_id' => $this->importRunId,
            'vehicles_total' => $this->vehiclesTotal,
            'vehicles_created' => $this->vehiclesCreated,
            'vehicles_updated' => $this->vehiclesUpdated,
            'vehicles_with_full_prices' => $this->vehiclesWithFullPrices,
            'vehicles_without_full_prices' => $this->vehiclesWithoutFullPrices,
            'active_vehicles' => $this->activeVehicles,
            'inactive_vehicles' => $this->inactiveVehicles,
            'inactive_vehicle_codes' => $this->inactiveVehicleCodes,
            'price_rows' => $this->priceRows,
            'warnings' => $this->warnings,
        ];
    }
}
