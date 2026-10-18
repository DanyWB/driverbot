<?php

namespace App\Domain\Pricing\Presenters;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\PricingPreset;
use App\Models\PricingPresetPrice;
use RuntimeException;

class PricingPresetPresenter
{
    /** @return array<string, mixed> */
    public function present(PricingPreset $preset): array
    {
        $preset->loadMissing(['prices.season', 'vehicles:id,pricing_preset_id']);

        return [
            'id' => (int) $preset->id,
            'name' => (string) $preset->name,
            'vehicle_type' => $preset->getRawOriginal('vehicle_type'),
            'version' => (int) $preset->version,
            'linked_vehicles_count' => $preset->vehicles->count(),
            'linked_vehicle_ids' => $preset->vehicles->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'prices' => $this->matrix($preset),
            'created_at' => $preset->created_at?->toIso8601String(),
            'updated_at' => $preset->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, array<string, int>> */
    private function matrix(PricingPreset $preset): array
    {
        $matrix = [];

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $price = $preset->prices->first(fn (PricingPresetPrice $price): bool => $price->season->seasonKey() === $season
                    && $price->getRawOriginal('tier_key') === $tier->value
                );

                if (! $price instanceof PricingPresetPrice) {
                    throw new RuntimeException("Pricing preset {$preset->id} does not contain a complete price matrix.");
                }

                $matrix[$season->value][$tier->value] = (int) $price->package_total;
            }
        }

        return $matrix;
    }
}
