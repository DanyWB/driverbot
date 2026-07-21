<?php

namespace App\Domain\Pricing\Imports\Data;

final readonly class PricingWorkbookData
{
    /**
     * @param  list<ImportedVehicle>  $vehicles
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $sourcePath,
        public string $sourceFilename,
        public string $sourceSha256,
        public array $vehicles,
        public array $warnings,
    ) {}
}
