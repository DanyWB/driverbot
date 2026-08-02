<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader2;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class InspectPricingWorkbook extends Command
{
    /** @var string */
    protected $signature = 'pricing:inspect-workbook
        {path : Absolute or project-relative workbook path}
        {--sheet=* : Sheet names to inspect; omit to list workbook sheets}
        {--from-row=1 : First row to inspect}
        {--to-row=80 : Last row to inspect}
        {--max-column=AZ : Last column to inspect}
        {--calculated : Show calculated values next to formulas}';

    /** @var string */
    protected $description = 'Inspect pricing workbook structure without changing the database';

    public function handle(): int
    {
        $path = $this->absolutePath((string) $this->argument('path'));

        if (! is_file($path)) {
            $this->error("Workbook not found: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $this->renderSheetList($reader, $path);

        $sheets = array_values(array_filter(
            $this->option('sheet'),
            fn (mixed $sheet): bool => is_string($sheet) && $sheet !== '',
        ));

        if ($sheets === []) {
            return self::SUCCESS;
        }

        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheets);
        $workbook = $reader->load($path);

        foreach ($sheets as $sheetName) {
            $worksheet = $workbook->getSheetByName($sheetName);

            if ($worksheet === null) {
                $this->warn("Sheet not found: {$sheetName}");

                continue;
            }

            $this->renderRows($worksheet);
        }

        $workbook->disconnectWorksheets();

        return self::SUCCESS;
    }

    private function renderSheetList(IReader2 $reader, string $path): void
    {
        $rows = array_map(
            fn (array $sheet): array => [
                $sheet['worksheetName'],
                $sheet['totalRows'],
                $sheet['totalColumns'],
                $sheet['lastColumnLetter'],
            ],
            $reader->listWorksheetInfo($path),
        );

        $this->table(['Sheet', 'Rows', 'Columns', 'Last column'], $rows);
    }

    private function renderRows(Worksheet $worksheet): void
    {
        $fromRow = max(1, (int) $this->option('from-row'));
        $toRow = min((int) $this->option('to-row'), $worksheet->getHighestDataRow());
        $requestedColumn = strtoupper((string) $this->option('max-column'));
        $maxColumn = min(
            Coordinate::columnIndexFromString($requestedColumn),
            Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
        );

        $this->newLine();
        $this->info("Sheet: {$worksheet->getTitle()}");

        for ($row = $fromRow; $row <= $toRow; $row++) {
            $values = [];

            for ($column = 1; $column <= $maxColumn; $column++) {
                $coordinate = Coordinate::stringFromColumnIndex($column).$row;
                $cell = $worksheet->getCell($coordinate);
                $value = $cell->getValue();

                if ($value === null || $value === '') {
                    continue;
                }

                $rendered = $this->renderValue($value);

                if ($this->option('calculated') && is_string($value) && str_starts_with($value, '=')) {
                    try {
                        $rendered .= ' => '.$this->renderValue($cell->getCalculatedValue());
                    } catch (Throwable $exception) {
                        $rendered .= ' => [calculation error: '.$exception->getMessage().']';
                    }
                }

                $values[] = "{$coordinate}={$rendered}";
            }

            if ($values !== []) {
                $this->line(implode(' | ', $values));
            }
        }
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function renderValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], trim((string) $value));
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[value]';
    }
}
