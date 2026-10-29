<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Data\PriceBreakdownItem;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\PricingSeasonMonth;
use App\Models\RentalPolicy;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class PricingService
{
    public function quote(Vehicle $vehicle, RentalPeriod $period, bool $requireBookable = true): PriceQuote
    {
        return DB::transaction(function () use ($vehicle, $period, $requireBookable): PriceQuote {
            // Every matrix writer locks the vehicle before changing settings
            // or prices. Never read its preset here: writers lock preset first,
            // so acquiring one after this vehicle would invert their order.
            $snapshot = Vehicle::query()->whereKey($vehicle->getKey())->sharedLock()->firstOrFail();

            return $this->quoteSnapshot($snapshot, $period, $requireBookable);
        });
    }

    private function quoteSnapshot(Vehicle $vehicle, RentalPeriod $period, bool $requireBookable): PriceQuote
    {
        if ($vehicle->archived_at !== null || ! $vehicle->is_active || ($requireBookable && ! $vehicle->is_visible_for_booking)) {
            throw new PricingException('vehicle_hidden', 'Vehicle is not available for booking.', [
                'vehicle_id' => $vehicle->id,
            ]);
        }

        $policy = RentalPolicy::query()->find(1);
        $minimumDays = $policy?->minimumFor($vehicle->category_id === null ? null : (int) $vehicle->category_id) ?? 1;
        if ($period->totalDays() < $minimumDays) {
            throw new PricingException('minimum_rental_days', "Minimum rental duration is {$minimumDays} days.", [
                'minimum_days' => $minimumDays,
                'total_days' => $period->totalDays(),
            ]);
        }

        if ($vehicle->calculation_settings !== null) {
            return $this->calculatorQuote($vehicle, $period);
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

    private function calculatorQuote(Vehicle $vehicle, RentalPeriod $period): PriceQuote
    {
        $rows = $vehicle->priceTiers()->where('is_active', true)->with('season')->get();
        $prices = [];
        $currency = null;
        foreach ($rows as $row) {
            if ($currency !== null && $currency !== $row->currency) {
                throw new PricingException('currency_mismatch', 'Price tiers use different currencies.');
            }
            $currency = $row->currency;
            $prices[$row->season->seasonKey()->value][$row->getRawOriginal('tier_key')] = (int) $row->package_total;
        }
        foreach (PricingCalculator::SEASONS as $season) {
            foreach (PricingCalculator::TIERS as $tier) {
                if (! isset($prices[$season][$tier])) {
                    throw new PricingException('price_missing', 'Calculator requires a complete active price matrix.', ['season' => $season, 'tier' => $tier]);
                }
            }
        }
        $calculation = app(PricingCalculator::class)->quote($prices, $vehicle->calculation_settings, $period);
        $total = $calculation['total'];
        $tier = PricingTier::forDuration($period->totalDays());

        return new PriceQuote(
            vehicleId: (int) $vehicle->id,
            startsOn: $period->startsOn->format('Y-m-d'),
            endsOn: $period->endsOn->format('Y-m-d'),
            totalDays: $period->totalDays(),
            tier: $tier,
            calculatedTotal: number_format($total, 6, '.', ''),
            roundedTotal: $total,
            finalTotal: $total,
            currency: $currency,
            breakdown: [new PriceBreakdownItem(
                season: $calculation['season'], days: $period->totalDays(), tier: $tier,
                packageTotal: $prices[$calculation['season']][$tier->value], anchorDays: $tier->anchorDays(),
                dailyRate: number_format($total / $period->totalDays(), 6, '.', ''), subtotal: number_format($total, 6, '.', ''),
            )],
            calculation: $calculation,
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
