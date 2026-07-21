<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Data\PricingCatalogAudit;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\PricingSeasonMonth;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PricingCatalogAuditor
{
    public function audit(): PricingCatalogAudit
    {
        $errors = [];
        $warnings = [];
        $months = PricingSeasonMonth::query()
            ->whereHas('season', fn ($query) => $query->where('is_active', true))
            ->orderBy('month')
            ->pluck('month')
            ->map(fn ($month): int => (int) $month)
            ->all();

        if ($months !== range(1, 12)) {
            $errors[] = 'Active pricing season mapping does not cover months 1-12 exactly once.';
        }

        $vehicles = Vehicle::query()->withCount([
            'priceTiers as active_price_tiers_count' => fn ($query) => $query->where('is_active', true),
        ])->orderBy('id')->get();
        $complete = 0;

        foreach ($vehicles as $vehicle) {
            $priceCountAttribute = $vehicle->getAttribute('active_price_tiers_count');
            $priceCount = is_numeric($priceCountAttribute) ? (int) $priceCountAttribute : 0;

            if ($priceCount === 15) {
                $complete++;
            } else {
                $warnings[] = "Vehicle {$vehicle->external_code} has {$priceCount}/15 active price tiers.";
            }

            if ($vehicle->is_visible_for_booking && $priceCount !== 15) {
                $errors[] = "Vehicle {$vehicle->external_code} is visible with an incomplete price matrix.";
            }

            if ($vehicle->is_visible_for_booking && ! $vehicle->is_active) {
                $errors[] = "Inactive vehicle {$vehicle->external_code} is visible for booking.";
            }
        }

        foreach (VehiclePriceTier::query()->get() as $price) {
            $tier = PricingTier::from((string) $price->getRawOriginal('tier_key'));
            $maxDays = $price->getAttribute('max_days');
            $normalizedMaxDays = is_numeric($maxDays) ? (int) $maxDays : null;

            if ((int) $price->min_days !== $tier->minimumDays()
                || $normalizedMaxDays !== $tier->maximumDays()
                || (int) $price->anchor_days !== $tier->anchorDays()) {
                $errors[] = "Price tier {$price->id} has inconsistent duration metadata.";
            }

            $expectedDailyRate = BigDecimal::of((string) $price->package_total)->dividedBy(
                $tier->anchorDays(),
                6,
                RoundingMode::HalfUp,
            );

            if (! $expectedDailyRate->isEqualTo(BigDecimal::of((string) $price->daily_rate))) {
                $errors[] = "Price tier {$price->id} has a stale daily rate.";
            }
        }

        $vehicleCount = $vehicles->count();

        return new PricingCatalogAudit(
            vehicles: $vehicleCount,
            activeVehicles: $vehicles->where('is_active', true)->count(),
            visibleVehicles: $vehicles->where('is_visible_for_booking', true)->count(),
            completePriceMatrices: $complete,
            incompletePriceMatrices: $vehicleCount - $complete,
            priceRows: VehiclePriceTier::query()->count(),
            errors: $errors,
            warnings: $warnings,
        );
    }
}
