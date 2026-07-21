<?php

namespace App\Domain\Pricing\Imports;

use App\Domain\Imports\Enums\ImportRunStatus;
use App\Domain\Pricing\Imports\Data\ImportedVehicle;
use App\Domain\Pricing\Imports\Data\PricingImportReport;
use App\Domain\Pricing\Imports\Data\PricingWorkbookData;
use App\Domain\Shared\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DataImportRun;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PricingWorkbookImporter
{
    public function preview(PricingWorkbookData $data): PricingImportReport
    {
        $existingCodes = Vehicle::query()
            ->whereIn('external_code', array_map(fn (ImportedVehicle $vehicle): string => $vehicle->externalCode, $data->vehicles))
            ->pluck('external_code')
            ->flip();

        $created = 0;
        $updated = 0;

        foreach ($data->vehicles as $vehicle) {
            if ($existingCodes->has($vehicle->externalCode)) {
                $updated++;
            } else {
                $created++;
            }
        }

        return $this->report($data, true, $created, $updated);
    }

    public function import(PricingWorkbookData $data, ?User $admin = null): PricingImportReport
    {
        $run = DataImportRun::query()->create([
            'type' => 'vehicle_pricing',
            'source_filename' => $data->sourceFilename,
            'source_sha256' => $data->sourceSha256,
            'status' => ImportRunStatus::Running,
            'created_by_admin_id' => $admin?->id,
            'started_at' => now(),
        ]);

        $created = 0;
        $updated = 0;

        try {
            return DB::transaction(function () use ($data, $run, $admin, &$created, &$updated): PricingImportReport {
                $seasons = PricingSeason::query()
                    ->where('is_active', true)
                    ->get()
                    ->keyBy(fn (PricingSeason $season): string => $season->seasonKey()->value);

                foreach ($data->vehicles as $sortOrder => $importedVehicle) {
                    $category = Category::query()->updateOrCreate(
                        ['code' => $importedVehicle->categoryCode],
                        [
                            'name' => $importedVehicle->categoryName,
                            'vehicle_type' => $importedVehicle->type,
                            'sort_order' => $this->categorySortOrder($importedVehicle->categoryCode),
                            'is_active' => true,
                        ],
                    );

                    $vehicle = Vehicle::query()->where('external_code', $importedVehicle->externalCode)->first();
                    $targetActive = $vehicle === null || $importedVehicle->hasExplicitActiveMarker
                        ? $importedVehicle->isActive
                        : (bool) $vehicle->is_active;
                    $attributes = [
                        'type' => $importedVehicle->type,
                        'category_id' => $category->id,
                        'name' => $importedVehicle->name,
                        'inventory_code' => $importedVehicle->inventoryCode,
                        'year' => $importedVehicle->year,
                        'is_visible_for_booking' => $importedVehicle->hasCompletePriceMatrix() && $targetActive,
                        'sort_order' => $sortOrder + 1,
                        'pricing_profile' => 'seasonal_tiers_v1',
                        'source_sheet' => $importedVehicle->sourceSheet,
                        'source_row' => $importedVehicle->sourceRow,
                        'source_import_run_id' => $run->id,
                        'imported_at' => now(),
                    ];

                    if ($vehicle === null) {
                        $vehicle = new Vehicle(['external_code' => $importedVehicle->externalCode]);
                        $attributes['is_active'] = $targetActive;
                        $created++;
                    } else {
                        if ($importedVehicle->hasExplicitActiveMarker) {
                            $attributes['is_active'] = $targetActive;
                        }

                        $updated++;
                    }

                    $vehicle->fill($attributes)->save();
                    $priceIds = [];

                    foreach ($importedVehicle->prices as $importedPrice) {
                        $season = $seasons->get($importedPrice->season->value);

                        if (! $season instanceof PricingSeason) {
                            throw new RuntimeException("Active season not found: {$importedPrice->season->value}");
                        }

                        $price = $vehicle->priceTiers()->updateOrCreate(
                            [
                                'pricing_season_id' => $season->id,
                                'tier_key' => $importedPrice->tier->value,
                            ],
                            [
                                'min_days' => $importedPrice->tier->minimumDays(),
                                'max_days' => $importedPrice->tier->maximumDays(),
                                'anchor_days' => $importedPrice->tier->anchorDays(),
                                'package_total' => $importedPrice->packageTotal,
                                'daily_rate' => (string) BigDecimal::of($importedPrice->packageTotal)->dividedBy(
                                    $importedPrice->tier->anchorDays(),
                                    6,
                                    RoundingMode::HalfUp,
                                ),
                                'currency' => (string) config('pricing_import.currency'),
                                'is_active' => true,
                                'source_sheet' => (string) config('pricing_import.prices_sheet'),
                                'source_row' => $importedPrice->sourceRow,
                                'source_import_run_id' => $run->id,
                            ],
                        );

                        $priceIds[] = $price->id;
                    }

                    $stalePrices = $vehicle->priceTiers();

                    if ($priceIds === []) {
                        $stalePrices->delete();
                    } else {
                        $stalePrices->whereNotIn('id', $priceIds)->delete();
                    }
                }

                $report = $this->report($data, false, $created, $updated, (string) $run->public_id);
                $run->update([
                    'status' => ImportRunStatus::Completed,
                    'summary' => $report->toArray(),
                    'finished_at' => now(),
                ]);

                AuditLog::query()->create([
                    'actor_type' => $admin === null ? ActorType::System : ActorType::Admin,
                    'actor_admin_id' => $admin?->id,
                    'subject_type' => DataImportRun::class,
                    'subject_id' => (string) $run->public_id,
                    'action' => 'pricing.workbook_imported',
                    'new_values' => $report->toArray(),
                ]);

                return $report;
            });
        } catch (Throwable $exception) {
            $run->update([
                'status' => ImportRunStatus::Failed,
                'summary' => [
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ],
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function report(
        PricingWorkbookData $data,
        bool $dryRun,
        int $created,
        int $updated,
        ?string $importRunId = null,
    ): PricingImportReport {
        $withFullPrices = count(array_filter(
            $data->vehicles,
            fn (ImportedVehicle $vehicle): bool => $vehicle->hasCompletePriceMatrix(),
        ));
        $activeVehicles = count(array_filter(
            $data->vehicles,
            fn (ImportedVehicle $vehicle): bool => $vehicle->isActive,
        ));
        $inactiveVehicleCodes = array_values(array_map(
            fn (ImportedVehicle $vehicle): string => $vehicle->externalCode,
            array_filter($data->vehicles, fn (ImportedVehicle $vehicle): bool => ! $vehicle->isActive),
        ));

        return new PricingImportReport(
            dryRun: $dryRun,
            sourceFilename: $data->sourceFilename,
            sourceSha256: $data->sourceSha256,
            vehiclesTotal: count($data->vehicles),
            vehiclesCreated: $created,
            vehiclesUpdated: $updated,
            vehiclesWithFullPrices: $withFullPrices,
            vehiclesWithoutFullPrices: count($data->vehicles) - $withFullPrices,
            activeVehicles: $activeVehicles,
            inactiveVehicles: count($data->vehicles) - $activeVehicles,
            inactiveVehicleCodes: $inactiveVehicleCodes,
            priceRows: array_sum(array_map(fn (ImportedVehicle $vehicle): int => count($vehicle->prices), $data->vehicles)),
            warnings: $data->warnings,
            importRunId: $importRunId,
        );
    }

    private function categorySortOrder(string $categoryCode): int
    {
        return match ($categoryCode) {
            'light-scooters' => 10,
            'comfort-scooters' => 20,
            'maxi-scooters' => 30,
            'cars' => 40,
            default => 100,
        };
    }
}
