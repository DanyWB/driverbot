<?php

namespace Database\Seeders;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Models\PricingSeason;
use App\Models\PricingSeasonMonth;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            PricingSeasonKey::High->value => ['name' => 'High season', 'sort_order' => 10, 'months' => [12, 1, 2, 3]],
            PricingSeasonKey::Middle->value => ['name' => 'Middle season', 'sort_order' => 20, 'months' => [4, 5, 10, 11]],
            PricingSeasonKey::Low->value => ['name' => 'Low season', 'sort_order' => 30, 'months' => [6, 7, 8, 9]],
        ];

        foreach ($definitions as $key => $definition) {
            $season = PricingSeason::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                ],
            );

            $rows = array_map(
                fn (int $month): array => [
                    'pricing_season_id' => $season->id,
                    'month' => $month,
                ],
                $definition['months'],
            );

            PricingSeasonMonth::query()->upsert($rows, ['month'], ['pricing_season_id']);
        }
    }
}
