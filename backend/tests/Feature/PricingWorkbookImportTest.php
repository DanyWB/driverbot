<?php

namespace Tests\Feature;

use App\Domain\Pricing\Imports\PricingWorkbookImporter;
use App\Domain\Pricing\Imports\PricingWorkbookParser;
use App\Models\DataImportRun;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class PricingWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private string $workbookPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $this->workbookPath = $directory.'/pricing-import-test.xlsx';
        $this->writeWorkbook($this->workbookPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->workbookPath);

        parent::tearDown();
    }

    public function test_dry_run_parses_real_xlsx_without_writing_to_database(): void
    {
        $data = app(PricingWorkbookParser::class)->parse($this->workbookPath);
        $report = app(PricingWorkbookImporter::class)->preview($data);

        $this->assertTrue($report->dryRun);
        $this->assertSame(2, $report->vehiclesTotal);
        $this->assertSame(1, $report->vehiclesWithFullPrices);
        $this->assertSame(1, $report->vehiclesWithoutFullPrices);
        $this->assertSame(1, $report->activeVehicles);
        $this->assertSame(1, $report->inactiveVehicles);
        $this->assertSame(15, $report->priceRows);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_repeated_apply_is_idempotent_and_hides_vehicle_without_prices(): void
    {
        $data = app(PricingWorkbookParser::class)->parse($this->workbookPath);
        $importer = app(PricingWorkbookImporter::class);

        $first = $importer->import($data);
        $second = $importer->import($data);

        $this->assertSame(2, $first->vehiclesCreated);
        $this->assertSame(0, $first->vehiclesUpdated);
        $this->assertSame(0, $second->vehiclesCreated);
        $this->assertSame(2, $second->vehiclesUpdated);
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseCount('vehicle_price_tiers', 15);
        $this->assertDatabaseCount('data_import_runs', 2);
        $this->assertSame(15, VehiclePriceTier::query()->count());

        $priced = Vehicle::query()->where('external_code', 'pcx-test-2026-A')->firstOrFail();
        $unpriced = Vehicle::query()->where('external_code', 'unpriced-list-2-xmax-300cc-abs-black-2019')->firstOrFail();

        $this->assertTrue($priced->is_visible_for_booking);
        $this->assertTrue($priced->is_active);
        $this->assertFalse($unpriced->is_visible_for_booking);
        $this->assertFalse($unpriced->is_active);
        $this->assertNotNull(DataImportRun::query()->latest('id')->firstOrFail()->summary);
        $this->artisan('pricing:audit')->assertSuccessful();
    }

    private function writeWorkbook(string $path): void
    {
        $workbook = new Spreadsheet;
        $prices = $workbook->getActiveSheet();
        $prices->setTitle('Prices FINAL!!!');
        $prices->setCellValue('B1', 'ALL VEHICLES LIST');
        $prices->setCellValue('E3', 'BIKE ID');
        $prices->setCellValue('G3', 'BIKE NAME');
        $prices->setCellValue('D5', 'M!');
        $prices->setCellValue('E5', 'pcx-test-2026-A');
        $prices->setCellValue('G5', 'PCX 160cc, ABS, Blue, 2026');

        foreach (range('H', 'V') as $offset => $column) {
            $prices->setCellValue("{$column}5", 1000 + ($offset * 100));
        }

        $prices->setCellValue('G8', 'BIKE NAME');

        $vehicles = $workbook->createSheet();
        $vehicles->setTitle('LIST BIKES');
        $vehicles->setCellValue('A2', 1);
        $vehicles->setCellValue('C2', true);
        $vehicles->setCellValue('D2', 'PCX 160cc, ABS, Blue, 2026');
        $vehicles->setCellValue('A3', 2);
        $vehicles->setCellValue('C3', false);
        $vehicles->setCellValue('D3', 'Xmax 300cc, ABS, Black, 2019');

        IOFactory::createWriter($workbook, 'Xlsx')->save($path);
        $workbook->disconnectWorksheets();
    }
}
