<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Models\PricingPreset;
use App\Models\PricingPresetPrice;
use App\Models\PricingSeason;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PricingPresetService
{
    /**
     * @param  array{name: string, vehicle_type?: string|null, prices: array<string, array<string, int>>, calculation_settings?:array<string,mixed>|null,sort_order?:int}  $data
     */
    public function create(array $data, int $adminId): PricingPreset
    {
        return DB::transaction(function () use ($data, $adminId): PricingPreset {
            $settings = isset($data['calculation_settings']) ? app(PricingSettings::class)->validate($data['calculation_settings']) : null;
            $prices = app(PricingSettings::class)->linkedPrices($data['prices'], $settings);
            $preset = PricingPreset::query()->create([
                'name' => $data['name'],
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'version' => 1,
                'sort_order' => $data['sort_order'] ?? ((int) PricingPreset::query()->max('sort_order') + 1),
                'calculation_settings' => $settings,
                'created_by_admin_id' => $adminId,
                'updated_by_admin_id' => $adminId,
            ]);

            $this->replacePrices($preset, $prices);

            return $this->fresh($preset);
        });
    }

    public function copy(
        PricingPreset $source,
        string $name,
        int $adminId,
        ?int $sourceVersion = null,
        string $locale = 'en',
    ): PricingPreset {
        return DB::transaction(function () use ($source, $name, $adminId, $sourceVersion, $locale): PricingPreset {
            $source = PricingPreset::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();

            if ($sourceVersion !== null) {
                $this->assertPresetVersion($source, $sourceVersion, $locale);
            }

            $matrix = $this->matrix($source);

            return $this->create([
                'name' => $name,
                'vehicle_type' => $source->getRawOriginal('vehicle_type'),
                'prices' => $matrix,
                'calculation_settings' => $source->calculation_settings,
            ], $adminId);
        });
    }

    /**
     * @param  array{name: string, vehicle_type?: string|null, version: int, expected_linked_vehicles_count: int, prices: array<string, array<string, int>>, calculation_settings?:array<string,mixed>|null,sort_order?:int}  $data
     */
    public function update(PricingPreset $preset, array $data, int $adminId, string $locale): PricingPreset
    {
        return DB::transaction(function () use ($preset, $data, $adminId, $locale): PricingPreset {
            $preset = PricingPreset::query()->whereKey($preset->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPresetVersion($preset, (int) $data['version'], $locale);

            $vehicles = Vehicle::query()
                ->where('pricing_preset_id', $preset->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($vehicles->count() !== (int) $data['expected_linked_vehicles_count']) {
                throw new PricingPresetConflictException(
                    'pricing_preset_links_conflict',
                    __('pricing_presets.links_conflict', [], $locale),
                    ['current_linked_vehicles_count' => $vehicles->count()],
                );
            }

            $vehicleType = $data['vehicle_type'] ?? null;
            $incompatible = $vehicleType === null
                ? null
                : $vehicles->first(fn (Vehicle $vehicle): bool => $vehicle->getRawOriginal('type') !== $vehicleType);

            if ($incompatible instanceof Vehicle) {
                throw ValidationException::withMessages([
                    'vehicle_type' => __('pricing_presets.linked_type_conflict', [], $locale),
                ]);
            }

            $settings = array_key_exists('calculation_settings', $data)
                ? ($data['calculation_settings'] === null ? null : app(PricingSettings::class)->validate($data['calculation_settings']))
                : $preset->calculation_settings;
            $prices = app(PricingSettings::class)->linkedPrices($data['prices'], $settings);
            $preset->forceFill([
                'name' => $data['name'],
                'calculation_settings' => $settings,
                'vehicle_type' => $vehicleType,
                'sort_order' => $data['sort_order'] ?? (int) $preset->sort_order,
                'version' => (int) $preset->version + 1,
                'updated_by_admin_id' => $adminId,
            ])->save();
            $this->replacePrices($preset, $prices);

            foreach ($vehicles as $vehicle) {
                $this->materialize($vehicle, $preset, $prices);
            }

            return $this->fresh($preset);
        });
    }

    public function attach(
        Vehicle $vehicle,
        PricingPreset $target,
        int $targetVersion,
        ?int $expectedPresetId,
        int $expectedPricingVersion,
        string $locale,
    ): Vehicle {
        return DB::transaction(function () use ($vehicle, $target, $targetVersion, $expectedPresetId, $expectedPricingVersion, $locale): Vehicle {
            $snapshotPresetId = $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id;
            $presetIds = array_values(array_unique(array_filter(
                [$expectedPresetId, (int) $target->id, $snapshotPresetId],
                fn (?int $id): bool => $id !== null,
            )));
            sort($presetIds);

            $lockedPresets = PricingPreset::query()
                ->whereIn('id', $presetIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $target = $lockedPresets->get((int) $target->id);

            if (! $target instanceof PricingPreset) {
                abort(404);
            }

            $vehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVehicleRevision($vehicle, $expectedPresetId, $expectedPricingVersion, $locale);
            $this->assertPresetVersion($target, $targetVersion, $locale);

            $targetType = $target->getRawOriginal('vehicle_type');
            if ($targetType !== null && $targetType !== $vehicle->getRawOriginal('type')) {
                throw ValidationException::withMessages([
                    'preset_id' => __('pricing_presets.vehicle_type_conflict', [], $locale),
                ]);
            }

            $this->materialize($vehicle, $target, $this->matrix($target));

            return $vehicle->refresh();
        });
    }

    public function detach(
        Vehicle $vehicle,
        int $presetVersion,
        int $expectedPresetId,
        int $expectedPricingVersion,
        string $locale,
    ): Vehicle {
        return DB::transaction(function () use ($vehicle, $presetVersion, $expectedPresetId, $expectedPricingVersion, $locale): Vehicle {
            $preset = PricingPreset::query()
                ->whereKey($expectedPresetId)
                ->lockForUpdate()
                ->first();
            $vehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVehicleRevision($vehicle, $expectedPresetId, $expectedPricingVersion, $locale);

            if (! $preset instanceof PricingPreset) {
                throw new PricingPresetConflictException(
                    'vehicle_pricing_conflict',
                    __('pricing_presets.vehicle_conflict', [], $locale),
                    $this->vehicleConflictContext($vehicle),
                );
            }

            $this->assertPresetVersion($preset, $presetVersion, $locale);
            $vehicle->forceFill([
                'pricing_preset_id' => null,
                'calculation_settings' => $preset->calculation_settings,
                'pricing_profile' => null,
                'pricing_version' => (int) $vehicle->pricing_version + 1,
            ])->save();

            return $vehicle->refresh();
        });
    }

    /** @return array<string, array<string, int>> */
    public function matrix(PricingPreset $preset): array
    {
        $preset->loadMissing('prices.season');
        $matrix = [];

        foreach (PricingSeasonKey::cases() as $seasonKey) {
            foreach (PricingTier::cases() as $tier) {
                $price = $preset->prices->first(fn (PricingPresetPrice $price): bool => $price->season->seasonKey() === $seasonKey
                    && $price->getRawOriginal('tier_key') === $tier->value
                );

                if (! $price instanceof PricingPresetPrice) {
                    throw new RuntimeException("Pricing preset {$preset->id} does not contain a complete price matrix.");
                }

                $matrix[$seasonKey->value][$tier->value] = (int) $price->package_total;
            }
        }

        return $matrix;
    }

    /** @param array<string, array<string, int>> $prices */
    private function replacePrices(PricingPreset $preset, array $prices): void
    {
        $seasons = $this->seasons();

        foreach (PricingSeasonKey::cases() as $seasonKey) {
            $season = $seasons[$seasonKey->value];

            foreach (PricingTier::cases() as $tier) {
                PricingPresetPrice::query()->updateOrCreate([
                    'pricing_preset_id' => $preset->id,
                    'pricing_season_id' => $season->id,
                    'tier_key' => $tier->value,
                ], [
                    'package_total' => $prices[$seasonKey->value][$tier->value],
                ]);
            }
        }
    }

    /** @param array<string, array<string, int>> $prices */
    private function materialize(Vehicle $vehicle, PricingPreset $preset, array $prices): void
    {
        $seasons = $this->seasons();

        foreach (PricingSeasonKey::cases() as $seasonKey) {
            $season = $seasons[$seasonKey->value];

            foreach (PricingTier::cases() as $tier) {
                $packageTotal = $prices[$seasonKey->value][$tier->value];
                $dailyRate = BigDecimal::of($packageTotal)->dividedBy(
                    $tier->anchorDays(),
                    6,
                    RoundingMode::HalfUp,
                );

                $vehicle->priceTiers()->updateOrCreate([
                    'pricing_season_id' => $season->id,
                    'tier_key' => $tier->value,
                ], [
                    'min_days' => $tier->minimumDays(),
                    'max_days' => $tier->maximumDays(),
                    'anchor_days' => $tier->anchorDays(),
                    'package_total' => $packageTotal,
                    'daily_rate' => (string) $dailyRate,
                    'currency' => (string) config('business.currency'),
                    'is_active' => true,
                    'source_sheet' => null,
                    'source_row' => null,
                    'source_import_run_id' => null,
                ]);
            }
        }

        $vehicle->forceFill([
            'pricing_preset_id' => $preset->id,
            'calculation_settings' => $preset->calculation_settings,
            'pricing_profile' => null,
            'pricing_version' => (int) $vehicle->pricing_version + 1,
        ])->save();
    }

    /** @return array<string, PricingSeason> */
    private function seasons(): array
    {
        $seasons = PricingSeason::query()
            ->whereIn('key', array_column(PricingSeasonKey::cases(), 'value'))
            ->get()
            ->keyBy(fn (PricingSeason $season): string => $season->seasonKey()->value);

        if ($seasons->count() !== count(PricingSeasonKey::cases())) {
            throw new RuntimeException('Pricing seasons are not configured.');
        }

        return $seasons->all();
    }

    private function assertPresetVersion(PricingPreset $preset, int $expectedVersion, string $locale): void
    {
        if ((int) $preset->version !== $expectedVersion) {
            throw new PricingPresetConflictException(
                'pricing_preset_version_conflict',
                __('pricing_presets.version_conflict', [], $locale),
                ['current_version' => (int) $preset->version],
            );
        }
    }

    private function assertVehicleRevision(Vehicle $vehicle, ?int $expectedPresetId, int $expectedPricingVersion, string $locale): void
    {
        $currentPresetId = $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id;

        if ($currentPresetId !== $expectedPresetId || (int) $vehicle->pricing_version !== $expectedPricingVersion) {
            throw new PricingPresetConflictException(
                'vehicle_pricing_conflict',
                __('pricing_presets.vehicle_conflict', [], $locale),
                $this->vehicleConflictContext($vehicle),
            );
        }
    }

    /** @return array{current_pricing_preset_id: int|null, current_vehicle_pricing_revision: int} */
    private function vehicleConflictContext(Vehicle $vehicle): array
    {
        return [
            'current_pricing_preset_id' => $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id,
            'current_vehicle_pricing_revision' => (int) $vehicle->pricing_version,
        ];
    }

    private function fresh(PricingPreset $preset): PricingPreset
    {
        return $preset->refresh()->load(['prices.season', 'vehicles:id,pricing_preset_id']);
    }
}
