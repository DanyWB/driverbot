<?php

namespace App\Console\Commands;

use App\Domain\Pricing\Imports\PricingWorkbookImporter;
use App\Domain\Pricing\Imports\PricingWorkbookParser;
use Illuminate\Console\Command;

class ImportPricingWorkbook extends Command
{
    /** @var string */
    protected $signature = 'pricing:import-workbook
        {path? : Absolute or project-relative workbook path}
        {--apply : Persist the import; without this flag only a dry-run is performed}
        {--json : Render the report as JSON}';

    /** @var string */
    protected $description = 'Validate and import vehicles and final prices from the approved workbook';

    public function handle(PricingWorkbookParser $parser, PricingWorkbookImporter $importer): int
    {
        $configuredPath = config('pricing_import.workbook_path');
        $path = (string) ($this->argument('path') ?: (is_string($configuredPath) ? $configuredPath : ''));

        if ($path === '') {
            $this->error('Provide a workbook path or set PRICING_WORKBOOK_PATH.');

            return self::FAILURE;
        }

        $path = $this->absolutePath($path);
        $this->components->info('Reading and validating workbook...');
        $data = $parser->parse($path);
        $report = $this->option('apply') ? $importer->import($data) : $importer->preview($data);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $report->dryRun ? 'dry-run' : 'apply'],
            ['File', $report->sourceFilename],
            ['SHA-256', $report->sourceSha256],
            ['Import run', $report->importRunId ?? '-'],
            ['Vehicles total', $report->vehiclesTotal],
            ['Vehicles created', $report->vehiclesCreated],
            ['Vehicles updated', $report->vehiclesUpdated],
            ['Complete price matrices', $report->vehiclesWithFullPrices],
            ['Incomplete price matrices', $report->vehiclesWithoutFullPrices],
            ['Active vehicles', $report->activeVehicles],
            ['Inactive vehicles', $report->inactiveVehicles],
            ['Price rows', $report->priceRows],
        ]);

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($report->inactiveVehicleCodes !== []) {
            $this->components->warn('Inactive from workbook: '.implode(', ', $report->inactiveVehicleCodes));
        }

        if ($report->dryRun) {
            $this->components->info('Dry-run complete. Re-run with --apply to persist this exact source file.');
        } else {
            $this->components->info('Import committed successfully.');
        }

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
