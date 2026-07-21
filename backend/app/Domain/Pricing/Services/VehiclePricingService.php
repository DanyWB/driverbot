<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\PricingSeason;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VehiclePricingService
{
    /**
     * @param  array<string, array<string, int|null>>  $prices
     * @param  array<string, array<string, bool>>  $enabled
     */
    public function replaceMatrix(Vehicle $vehicle, array $prices, array $enabled): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $prices, $enabled): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $seasons = PricingSeason::query()
                ->whereIn('key', array_column(PricingSeasonKey::cases(), 'value'))
                ->get()
                ->keyBy(fn (PricingSeason $season): string => $season->seasonKey()->value);

            if ($seasons->count() !== count(PricingSeasonKey::cases())) {
                throw new RuntimeException('Pricing seasons are not configured.');
            }

            foreach (PricingSeasonKey::cases() as $seasonKey) {
                $season = $seasons->get($seasonKey->value);

                if (! $season instanceof PricingSeason) {
                    throw new RuntimeException("Pricing season {$seasonKey->value} is not configured.");
                }

                foreach (PricingTier::cases() as $tier) {
                    $packageTotal = $prices[$seasonKey->value][$tier->value] ?? null;

                    if ($packageTotal === null) {
                        VehiclePriceTier::query()
                            ->where('vehicle_id', $vehicle->id)
                            ->where('pricing_season_id', $season->id)
                            ->where('tier_key', $tier->value)
                            ->delete();

                        continue;
                    }

                    $dailyRate = BigDecimal::of($packageTotal)->dividedBy(
                        $tier->anchorDays(),
                        6,
                        RoundingMode::HalfUp,
                    );

                    VehiclePriceTier::query()->updateOrCreate([
                        'vehicle_id' => $vehicle->id,
                        'pricing_season_id' => $season->id,
                        'tier_key' => $tier->value,
                    ], [
                        'min_days' => $tier->minimumDays(),
                        'max_days' => $tier->maximumDays(),
                        'anchor_days' => $tier->anchorDays(),
                        'package_total' => $packageTotal,
                        'daily_rate' => (string) $dailyRate,
                        'currency' => (string) config('business.currency'),
                        'is_active' => (bool) ($enabled[$seasonKey->value][$tier->value] ?? false),
                        'source_sheet' => null,
                        'source_row' => null,
                    ]);
                }
            }

            $activeCount = $vehicle->priceTiers()->where('is_active', true)->count();

            if ($activeCount !== 15 && $vehicle->is_visible_for_booking) {
                $vehicle->forceFill(['is_visible_for_booking' => false])->save();
            }

            return $vehicle->refresh();
        });
    }

    /** @return array<string, array<string, array<string, int|bool|string|null>>> */
    public function matrix(Vehicle $vehicle): array
    {
        $rows = $vehicle->priceTiers()->with('season')->get();
        $matrix = [];

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $row = $rows->first(fn (VehiclePriceTier $price): bool => $price->season->seasonKey() === $season
                    && $price->getRawOriginal('tier_key') === $tier->value);

                $matrix[$season->value][$tier->value] = [
                    'package_total' => $row instanceof VehiclePriceTier ? (int) $row->package_total : null,
                    'daily_rate' => $row instanceof VehiclePriceTier ? (string) $row->daily_rate : null,
                    'is_active' => $row instanceof VehiclePriceTier && (bool) $row->is_active,
                    'updated_at' => $row?->updated_at?->toIso8601String(),
                ];
            }
        }

        return $matrix;
    }
}
