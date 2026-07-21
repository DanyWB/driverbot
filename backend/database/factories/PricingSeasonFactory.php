<?php

namespace Database\Factories;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Models\PricingSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingSeason> */
class PricingSeasonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->randomElement(PricingSeasonKey::cases()),
            'name' => fake()->word(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
