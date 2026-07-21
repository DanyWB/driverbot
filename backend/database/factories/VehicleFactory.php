<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\Category;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'external_code' => fake()->unique()->slug(3),
            'type' => VehicleType::Scooter,
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'characteristics_text' => null,
            'is_active' => true,
            'is_visible_for_booking' => false,
            'sort_order' => 0,
        ];
    }
}
