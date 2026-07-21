<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Data\PriceBreakdownItem;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\PricingSeasonMonth;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

class PricingService
{
    public function quote(Vehicle $vehicle, RentalPeriod $period, bool $requireBookable = true): PriceQuote
    {
        if (! $vehicle->is_active || ($requireBookable && ! $vehicle->is_visible_for_booking)) {
            throw new PricingException('vehicle_hidden', 'Vehicle is not available for booking.', [
                'vehicle_id' => $vehicle->id,
            ]);
        }

        $tier = PricingTier::forDuration($period->totalDays());
        $seasonByMonth = $this->seasonByMonth();
        $daysBySeason = [];

        foreach ($period->dates() as $date) {
            $month = (int) $date->format('n');
            $season = $seasonByMonth[$month] ?? null;

            if ($season === null) {
                throw new PricingException('season_not_found', 'No active pricing season exists for rental date.', [
                    'date' => $date->format('Y-m-d'),
                    'month' => $month,
                ]);
            }

            $daysBySeason[$season] = ($daysBySeason[$season] ?? 0) + 1;
        }

        $prices = VehiclePriceTier::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('tier_key', $tier->value)
            ->where('is_active', true)
            ->with('season')
            ->get()
            ->keyBy(fn (VehiclePriceTier $price): string => $price->season->seasonKey()->value);

        $total = BigRational::zero();
        $currency = null;
        $breakdown = [];

        foreach ($daysBySeason as $season => $days) {
            $price = $prices->get($season);

            if (! $price instanceof VehiclePriceTier) {
                throw new PricingException('price_missing', 'Vehicle price is missing for the selected season and tier.', [
                    'vehicle_id' => $vehicle->id,
                    'season' => $season,
                    'tier' => $tier->value,
                ]);
            }

            if ($currency !== null && $currency !== $price->currency) {
                throw new PricingException('currency_mismatch', 'Price tiers use different currencies.', [
                    'vehicle_id' => $vehicle->id,
                ]);
            }

            $currency = $price->currency;
            $dailyRate = BigRational::of($price->package_total)->dividedBy($price->anchor_days);
            $subtotal = $dailyRate->multipliedBy($days);
            $total = $total->plus($subtotal);

            $breakdown[] = new PriceBreakdownItem(
                season: $season,
                days: $days,
                tier: $tier,
                packageTotal: (int) $price->package_total,
                anchorDays: (int) $price->anchor_days,
                dailyRate: (string) $dailyRate->toScale(6, RoundingMode::HalfUp),
                subtotal: (string) $subtotal->toScale(6, RoundingMode::HalfUp),
            );
        }

        $calculatedTotal = (string) $total->toScale(6, RoundingMode::HalfUp);
        $roundedTotal = (int) (string) $total
            ->dividedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->multipliedBy(100);

        return new PriceQuote(
            vehicleId: $vehicle->id,
            startsOn: $period->startsOn->format('Y-m-d'),
            endsOn: $period->endsOn->format('Y-m-d'),
            totalDays: $period->totalDays(),
            tier: $tier,
            calculatedTotal: $calculatedTotal,
            roundedTotal: $roundedTotal,
            finalTotal: $roundedTotal,
            currency: $currency ?? config('business.currency'),
            breakdown: $breakdown,
        );
    }

    /** @return array<int, string> */
    private function seasonByMonth(): array
    {
        $months = PricingSeasonMonth::query()
            ->whereHas('season', fn ($query) => $query->where('is_active', true))
            ->with('season')
            ->get();
        $result = [];

        foreach ($months as $month) {
            $result[(int) $month->month] = $month->season->seasonKey()->value;
        }

        return $result;
    }
}
