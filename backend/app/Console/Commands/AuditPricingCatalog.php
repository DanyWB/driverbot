<?php

namespace App\Console\Commands;

use App\Domain\Pricing\Services\PricingCatalogAuditor;
use Illuminate\Console\Command;

class AuditPricingCatalog extends Command
{
    /** @var string */
    protected $signature = 'pricing:audit {--json : Render the report as JSON}';

    /** @var string */
    protected $description = 'Audit pricing seasons, vehicle visibility and price matrix integrity';

    public function handle(PricingCatalogAuditor $auditor): int
    {
        $audit = $auditor->audit();

        if ($this->option('json')) {
            $this->line(json_encode($audit->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $audit->passed() ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Vehicles', $audit->vehicles],
            ['Active', $audit->activeVehicles],
            ['Visible', $audit->visibleVehicles],
            ['Complete price matrices', $audit->completePriceMatrices],
            ['Incomplete price matrices', $audit->incompletePriceMatrices],
            ['Price rows', $audit->priceRows],
        ]);

        foreach ($audit->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($audit->errors as $error) {
            $this->components->error($error);
        }

        if ($audit->passed()) {
            $this->components->info('Pricing catalog audit passed.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
