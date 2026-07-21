<?php

namespace Database\Factories;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'vehicle_type' => VehicleType::Scooter,
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
