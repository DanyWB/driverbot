<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Models\PricingPreset;
use App\Models\PricingSeason;
use App\Models\PricingSeasonMonth;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Global month → season mapping.
 *
 * The mapping lives in two places: the pricing_season_months table (legacy
 * vehicle quotes) and prefs.seasonCalendar inside every calculator settings
 * blob (presets and the vehicles materialized from them). Applying a new
 * calendar rewrites all of them in one transaction so every price agrees.
 */
class PricingSeasonCalendarService
{
    /** @return list<string> twelve season keys, January first */
    public function current(): array
    {
        $months = PricingSeasonMonth::query()->with('season')->get();
        $calendar = PricingCalculator::defaults()['prefs']['seasonCalendar'];

        foreach ($months as $month) {
            $index = (int) $month->month - 1;
            if ($index >= 0 && $index < 12 && $month->season instanceof PricingSeason) {
                $calendar[$index] = $month->season->seasonKey()->value;
            }
        }

        return array_values($calendar);
    }

    /**
     * @param  list<string>  $calendar
     * @return array{previous: list<string>, calendar: list<string>, presets: list<PricingPreset>, vehicles: int}
     */
    public function apply(array $calendar, int $adminId): array
    {
        $calendar = array_values($calendar);

        return DB::transaction(function () use ($calendar, $adminId): array {
            // Same lock order as preset writers: presets first, then vehicles.
            $presets = PricingPreset::query()->orderBy('id')->lockForUpdate()->get();
            $vehicles = Vehicle::query()->whereNotNull('calculation_settings')->orderBy('id')->lockForUpdate()->get();
            $previous = $this->current();

            $seasons = PricingSeason::query()
                ->whereIn('key', array_column(PricingSeasonKey::cases(), 'value'))
                ->get()
                ->keyBy(fn (PricingSeason $season): string => $season->seasonKey()->value);

            if ($seasons->count() !== count(PricingSeasonKey::cases())) {
                throw new RuntimeException('Pricing seasons are not configured.');
            }

            $rows = [];
            foreach ($calendar as $index => $seasonKey) {
                $rows[] = ['month' => $index + 1, 'pricing_season_id' => $seasons[$seasonKey]->id];
            }
            PricingSeasonMonth::query()->upsert($rows, ['month'], ['pricing_season_id']);

            $changedPresets = [];
            foreach ($presets as $preset) {
                $settings = $preset->calculation_settings;
                if (! is_array($settings) || ($settings['prefs']['seasonCalendar'] ?? null) === $calendar) {
                    continue;
                }
                $settings['prefs']['seasonCalendar'] = $calendar;
                $preset->forceFill([
                    'calculation_settings' => $settings,
                    'version' => (int) $preset->version + 1,
                    'updated_by_admin_id' => $adminId,
                ])->save();
                $changedPresets[] = $preset;
            }

            $changedVehicles = 0;
            foreach ($vehicles as $vehicle) {
                $settings = $vehicle->calculation_settings;
                if (! is_array($settings) || ($settings['prefs']['seasonCalendar'] ?? null) === $calendar) {
                    continue;
                }
                $settings['prefs']['seasonCalendar'] = $calendar;
                $vehicle->forceFill([
                    'calculation_settings' => $settings,
                    'pricing_version' => (int) $vehicle->pricing_version + 1,
                ])->save();
                $changedVehicles++;
            }

            return ['previous' => $previous, 'calendar' => $calendar, 'presets' => $changedPresets, 'vehicles' => $changedVehicles];
        });
    }
}
