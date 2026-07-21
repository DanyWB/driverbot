<?php

namespace App\Domain\Pricing\Imports;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Imports\Data\ImportedPrice;
use App\Domain\Pricing\Imports\Data\ImportedVehicle;
use App\Domain\Pricing\Imports\Data\PricingWorkbookData;
use App\Domain\Vehicles\Enums\VehicleType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class PricingWorkbookParser
{
    /** @var array<string, array{code: string, name: string, type: VehicleType}> */
    private const CATEGORIES = [
        'S!' => ['code' => 'light-scooters', 'name' => 'Light scooters', 'type' => VehicleType::Scooter],
        'M!' => ['code' => 'comfort-scooters', 'name' => 'Comfort scooters', 'type' => VehicleType::Scooter],
        'B!' => ['code' => 'maxi-scooters', 'name' => 'Maxi scooters', 'type' => VehicleType::Scooter],
        'C!' => ['code' => 'cars', 'name' => 'Cars', 'type' => VehicleType::Car],
    ];

    /** @var array<string, array{PricingSeasonKey, PricingTier}> */
    private const PRICE_COLUMNS = [
        'H' => [PricingSeasonKey::High, PricingTier::OneDay],
        'I' => [PricingSeasonKey::High, PricingTier::SevenDays],
        'J' => [PricingSeasonKey::High, PricingTier::FourteenDays],
        'K' => [PricingSeasonKey::High, PricingTier::TwentyOneDays],
        'L' => [PricingSeasonKey::High, PricingTier::Month],
        'M' => [PricingSeasonKey::Middle, PricingTier::OneDay],
        'N' => [PricingSeasonKey::Middle, PricingTier::SevenDays],
        'O' => [PricingSeasonKey::Middle, PricingTier::FourteenDays],
        'P' => [PricingSeasonKey::Middle, PricingTier::TwentyOneDays],
        'Q' => [PricingSeasonKey::Middle, PricingTier::Month],
        'R' => [PricingSeasonKey::Low, PricingTier::OneDay],
        'S' => [PricingSeasonKey::Low, PricingTier::SevenDays],
        'T' => [PricingSeasonKey::Low, PricingTier::FourteenDays],
        'U' => [PricingSeasonKey::Low, PricingTier::TwentyOneDays],
        'V' => [PricingSeasonKey::Low, PricingTier::Month],
    ];

    public function parse(string $path): PricingWorkbookData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Workbook is not readable: {$path}");
        }

        $pricesSheetName = (string) config('pricing_import.prices_sheet');
        $vehiclesSheetName = (string) config('pricing_import.vehicles_sheet');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$pricesSheetName, $vehiclesSheetName]);
        $workbook = $reader->load($path);
        $pricesSheet = $workbook->getSheetByName($pricesSheetName);
        $vehiclesSheet = $workbook->getSheetByName($vehiclesSheetName);

        if ($pricesSheet === null || $vehiclesSheet === null) {
            $workbook->disconnectWorksheets();

            throw new InvalidArgumentException('Workbook does not contain the required pricing and vehicle sheets.');
        }

        $warnings = [];
        $priceRows = $this->parseFinalPriceRows($pricesSheet, $warnings);
        $listRows = $this->parseVehicleListRows($vehiclesSheet);
        $vehicles = $this->mergeVehicleSources($priceRows, $listRows, $warnings);
        $workbook->disconnectWorksheets();

        $sourceSha256 = hash_file('sha256', $path);

        if ($sourceSha256 === false) {
            throw new InvalidArgumentException('Cannot calculate the workbook SHA-256 hash.');
        }

        return new PricingWorkbookData(
            sourcePath: $path,
            sourceFilename: basename($path),
            sourceSha256: $sourceSha256,
            vehicles: $vehicles,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function parseFinalPriceRows(Worksheet $sheet, array &$warnings): array
    {
        $titleRow = null;

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            if (strtoupper($this->stringValue($sheet->getCell("B{$row}"))) === 'ALL VEHICLES LIST') {
                $titleRow = $row;
                break;
            }
        }

        if ($titleRow === null) {
            throw new InvalidArgumentException('The ALL VEHICLES LIST block was not found.');
        }

        $headerRow = null;

        for ($row = $titleRow + 1; $row <= min($titleRow + 15, $sheet->getHighestDataRow()); $row++) {
            if (strtoupper($this->stringValue($sheet->getCell("E{$row}"))) === 'BIKE ID') {
                $headerRow = $row;
                break;
            }
        }

        if ($headerRow === null) {
            throw new InvalidArgumentException('The final vehicle price header was not found.');
        }

        $rows = [];

        for ($row = $headerRow + 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $name = $this->stringValue($sheet->getCell("G{$row}"));
            $externalCode = $this->stringValue($sheet->getCell("E{$row}"));

            if ($row > $headerRow + 2 && strtoupper($name) === 'BIKE NAME') {
                break;
            }

            if ($externalCode === '' && $name === '') {
                continue;
            }

            if ($externalCode === '' || $name === '') {
                $warnings[] = "Ignored incomplete final price row {$row}.";

                continue;
            }

            $categoryMarker = strtoupper($this->stringValue($sheet->getCell("D{$row}")));
            $category = self::CATEGORIES[$categoryMarker] ?? null;

            if ($category === null) {
                $warnings[] = "Vehicle {$externalCode} has unknown category marker '{$categoryMarker}'.";
                $category = $this->inferCategory($name);
            }

            $prices = [];

            foreach (self::PRICE_COLUMNS as $column => [$season, $tier]) {
                $value = $this->calculatedValue($sheet->getCell("{$column}{$row}"));

                if (! is_numeric($value)) {
                    $warnings[] = "Vehicle {$externalCode} is missing {$season->value}/{$tier->value} price at {$column}{$row}.";

                    continue;
                }

                $decimalValue = BigDecimal::of((string) $value);

                if ($decimalValue->isNegative()) {
                    $warnings[] = "Vehicle {$externalCode} has a negative {$season->value}/{$tier->value} price at {$column}{$row}.";

                    continue;
                }

                $integerValue = $decimalValue->toScale(0, RoundingMode::HalfUp);

                if (! $decimalValue->isEqualTo($integerValue)) {
                    $warnings[] = "Price at {$column}{$row} was rounded to whole THB during import.";
                }

                $prices[] = new ImportedPrice($season, $tier, (int) (string) $integerValue, $row);
            }

            $rows[] = [
                'external_code' => $externalCode,
                'category' => $category,
                'name' => $name,
                'owner' => $this->ownerFromExternalCode($externalCode),
                'normalized_name' => $this->normalizeName($name),
                'source_row' => $row,
                'prices' => $prices,
            ];
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The final vehicle price block contains no importable rows.');
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function parseVehicleListRows(Worksheet $sheet): array
    {
        $rows = [];

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawName = $this->stringValue($sheet->getCell("D{$row}"));

            if ($rawName === '' || preg_match('/\b(?:19|20)\d{2}\b/', $rawName) !== 1) {
                continue;
            }

            [$name, $owner] = $this->stripOwnerSuffix($rawName);
            $inventoryNumber = $this->stringValue($sheet->getCell("A{$row}"));
            $active = $this->parseActiveMarker($this->calculatedValue($sheet->getCell("C{$row}")));

            $rows[] = [
                'name' => $name,
                'owner' => $owner,
                'normalized_name' => $this->normalizeName($name),
                'inventory_code' => is_numeric($inventoryNumber) ? 'LIST-'.(int) $inventoryNumber : 'LIST-ROW-'.$row,
                'active' => $active,
                'source_row' => $row,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $priceRows
     * @param  list<array<string, mixed>>  $listRows
     * @param  list<string>  $warnings
     * @return list<ImportedVehicle>
     */
    private function mergeVehicleSources(array $priceRows, array $listRows, array &$warnings): array
    {
        $hasActiveMarkers = collect($listRows)->contains(fn (array $row): bool => $row['active'] !== null);

        if (! $hasActiveMarkers) {
            $warnings[] = 'No explicit active markers were found in LIST BIKES; all imported vehicles default to active.';
        }

        $usedPriceRows = [];
        $vehicles = [];

        foreach ($listRows as $listRow) {
            $candidateIndexes = [];

            foreach ($priceRows as $index => $priceRow) {
                if (isset($usedPriceRows[$index]) || ! $this->namesMatch($listRow['normalized_name'], $priceRow['normalized_name'])) {
                    continue;
                }

                if ($listRow['owner'] !== null && $listRow['owner'] !== $priceRow['owner']) {
                    continue;
                }

                $candidateIndexes[] = $index;
            }

            if (count($candidateIndexes) === 1) {
                $index = $candidateIndexes[0];
                $usedPriceRows[$index] = true;
                $priceRow = $priceRows[$index];

                $vehicles[] = $this->makeVehicle(
                    priceRow: $priceRow,
                    listRow: $listRow,
                    isActive: $hasActiveMarkers ? (bool) ($listRow['active'] ?? false) : true,
                    hasExplicitActiveMarker: $hasActiveMarkers,
                );

                continue;
            }

            if (count($candidateIndexes) > 1) {
                $warnings[] = "Ambiguous price match for LIST BIKES row {$listRow['source_row']}: {$listRow['name']}.";
            } else {
                $warnings[] = "Prices missing for LIST BIKES row {$listRow['source_row']}: {$listRow['name']}.";
            }

            $category = $this->inferCategory($listRow['name']);
            $vehicles[] = new ImportedVehicle(
                externalCode: $this->unpricedExternalCode($listRow),
                type: $category['type'],
                categoryCode: $category['code'],
                categoryName: $category['name'],
                name: $listRow['name'],
                inventoryCode: $listRow['inventory_code'],
                year: $this->extractYear($listRow['name']),
                isActive: $hasActiveMarkers ? (bool) ($listRow['active'] ?? false) : true,
                hasExplicitActiveMarker: $hasActiveMarkers,
                sourceSheet: (string) config('pricing_import.vehicles_sheet'),
                sourceRow: $listRow['source_row'],
                prices: [],
            );
        }

        foreach ($priceRows as $index => $priceRow) {
            if (isset($usedPriceRows[$index])) {
                continue;
            }

            $warnings[] = "Final price row {$priceRow['source_row']} is absent from LIST BIKES: {$priceRow['name']}.";
            $vehicles[] = $this->makeVehicle(
                priceRow: $priceRow,
                listRow: null,
                isActive: true,
                hasExplicitActiveMarker: false,
            );
        }

        return $vehicles;
    }

    /**
     * @param  array<string, mixed>  $priceRow
     * @param  array<string, mixed>|null  $listRow
     */
    private function makeVehicle(array $priceRow, ?array $listRow, bool $isActive, bool $hasExplicitActiveMarker): ImportedVehicle
    {
        $category = $priceRow['category'];

        return new ImportedVehicle(
            externalCode: $priceRow['external_code'],
            type: $category['type'],
            categoryCode: $category['code'],
            categoryName: $category['name'],
            name: $priceRow['name'],
            inventoryCode: $listRow['inventory_code'] ?? null,
            year: $this->extractYear($priceRow['name']),
            isActive: $isActive,
            hasExplicitActiveMarker: $hasExplicitActiveMarker,
            sourceSheet: $listRow === null
                ? (string) config('pricing_import.prices_sheet')
                : (string) config('pricing_import.vehicles_sheet'),
            sourceRow: $listRow['source_row'] ?? $priceRow['source_row'],
            prices: $priceRow['prices'],
        );
    }

    private function calculatedValue(Cell $cell): mixed
    {
        try {
            return $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("Cannot calculate workbook cell {$cell->getCoordinate()}.", previous: $exception);
        }
    }

    private function stringValue(Cell $cell): string
    {
        $value = $this->calculatedValue($cell);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array{string, string|null} */
    private function stripOwnerSuffix(string $name): array
    {
        if (preg_match('/^(.*\b(?:19|20)\d{2})\s+([AP])$/i', trim($name), $matches) === 1) {
            return [trim($matches[1]), strtoupper($matches[2]) === 'P' ? 'PiC' : 'A'];
        }

        return [trim($name), null];
    }

    private function ownerFromExternalCode(string $externalCode): ?string
    {
        return match (true) {
            str_ends_with(strtolower($externalCode), '-pic') => 'PiC',
            str_ends_with(strtolower($externalCode), '-a') => 'A',
            default => null,
        };
    }

    private function normalizeName(string $name): string
    {
        $normalized = strtolower($name);
        $normalized = str_replace(['n-max', 'n max'], 'nmax', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function namesMatch(string $left, string $right): bool
    {
        return $left === $right
            || str_ends_with($left, $right)
            || str_ends_with($right, $left);
    }

    private function parseActiveMarker(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', 'yes', 'active', 'x', '✓', '✔', '☑' => true,
            'false', 'no', 'inactive', '✗', '✘', '☐' => false,
            default => null,
        };
    }

    /** @return array{code: string, name: string, type: VehicleType} */
    private function inferCategory(string $name): array
    {
        if (preg_match('/\b(toyota|mazda|xpander|hr-v)\b/i', $name) === 1) {
            return self::CATEGORIES['C!'];
        }

        if (preg_match('/\b(?:300|350)cc\b/i', $name) === 1) {
            return self::CATEGORIES['B!'];
        }

        if (preg_match('/\b(?:110|125)cc\b/i', $name) === 1) {
            return self::CATEGORIES['S!'];
        }

        return self::CATEGORIES['M!'];
    }

    private function extractYear(string $name): ?int
    {
        return preg_match('/\b((?:19|20)\d{2})\b/', $name, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /** @param array<string, mixed> $listRow */
    private function unpricedExternalCode(array $listRow): string
    {
        return Str::limit(
            'unpriced-'.strtolower($listRow['inventory_code']).'-'.Str::slug($listRow['name']),
            160,
            '',
        );
    }
}
