<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Domain\Vehicles\Exceptions\VehicleCatalogException;
use App\Models\Category;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleCatalogService
{
    /** @param array<string, mixed> $attributes */
    public function createVehicle(array $attributes): Vehicle
    {
        return DB::transaction(function () use ($attributes): Vehicle {
            $vehicle = new Vehicle;
            $vehicle->fill($this->normalizeVehicleAttributes($attributes));
            $this->enforceVehicleInvariants($vehicle);
            $vehicle->save();

            return $vehicle->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateVehicle(Vehicle $vehicle, array $attributes): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $attributes): Vehicle {
            $vehicle->fill($this->normalizeVehicleAttributes($attributes, $vehicle));
            $this->enforceVehicleInvariants($vehicle);
            $vehicle->save();

            return $vehicle->refresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createCategory(array $attributes): Category
    {
        $category = new Category;
        $category->fill($this->normalizeCategoryAttributes($attributes));
        $category->save();

        return $category->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function updateCategory(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            $normalized = $this->normalizeCategoryAttributes($attributes, $category);
            $newType = $normalized['vehicle_type'] ?? null;

            if ($newType !== null && $category->vehicles()->where('type', '!=', $newType)->exists()) {
                throw new VehicleCatalogException(
                    'category_type_conflict',
                    'Category type does not match one or more assigned vehicles.',
                );
            }

            $category->fill($normalized);
            $category->save();

            if (! $category->is_active) {
                $category->vehicles()->where('is_visible_for_booking', true)->update([
                    'is_visible_for_booking' => false,
                    'updated_at' => now(),
                ]);
            }

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeVehicleAttributes(array $attributes, ?Vehicle $vehicle = null): array
    {
        $name = trim((string) $attributes['name']);
        $externalCode = trim((string) ($attributes['external_code'] ?? ''));

        if ($externalCode === '') {
            $externalCode = $vehicle instanceof Vehicle
                ? (string) $vehicle->external_code
                : $this->uniqueCode('vehicles', 'external_code', $name);
        }

        return [
            'external_code' => $externalCode,
            'type' => (string) $attributes['type'],
            'category_id' => $attributes['category_id'] ?? null,
            'name' => $name,
            'inventory_code' => $this->nullableString($attributes['inventory_code'] ?? null),
            'year' => $attributes['year'] ?? null,
            'description' => $this->nullableString($attributes['description'] ?? null),
            'characteristics_text' => $this->nullableString($attributes['characteristics_text'] ?? null),
            'emoji' => $this->nullableString($attributes['emoji'] ?? null),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
            'is_visible_for_booking' => (bool) ($attributes['is_visible_for_booking'] ?? false),
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'pricing_profile' => $this->nullableString($attributes['pricing_profile'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeCategoryAttributes(array $attributes, ?Category $category = null): array
    {
        $name = trim((string) $attributes['name']);
        $code = trim((string) ($attributes['code'] ?? ''));

        if ($code === '') {
            $code = $category instanceof Category
                ? (string) $category->code
                : $this->uniqueCode('categories', 'code', $name);
        }

        return [
            'code' => $code,
            'name' => $name,
            'vehicle_type' => $this->nullableString($attributes['vehicle_type'] ?? null),
            'description' => $this->nullableString($attributes['description'] ?? null),
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'is_active' => (bool) ($attributes['is_active'] ?? false),
        ];
    }

    private function enforceVehicleInvariants(Vehicle $vehicle): void
    {
        $type = $vehicle->getAttribute('type');
        $typeValue = $type instanceof VehicleType ? $type->value : (string) $type;
        $categoryId = $vehicle->getAttribute('category_id');

        if ($categoryId !== null) {
            $category = Category::query()->find($categoryId);

            if (! $category instanceof Category) {
                throw new VehicleCatalogException('category_not_found', 'Selected category was not found.');
            }

            $categoryType = $category->getRawOriginal('vehicle_type');

            if ($categoryType !== null && $categoryType !== $typeValue) {
                throw new VehicleCatalogException('category_type_mismatch', 'Selected category has a different vehicle type.');
            }

            if ($vehicle->is_visible_for_booking && ! $category->is_active) {
                throw new VehicleCatalogException('category_inactive', 'A vehicle in an inactive category cannot be visible for booking.');
            }
        }

        if (! $vehicle->is_active) {
            $vehicle->is_visible_for_booking = false;
        }

        if ($vehicle->is_visible_for_booking) {
            $activePrices = $vehicle->exists
                ? $vehicle->priceTiers()->where('is_active', true)->count()
                : 0;

            if ($activePrices !== 15) {
                throw new VehicleCatalogException(
                    'prices_incomplete',
                    'Complete all 15 active price tiers before making this vehicle visible.',
                    ['active_price_tiers' => $activePrices],
                );
            }
        }
    }

    private function uniqueCode(string $table, string $column, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $candidate = $base;
        $suffix = 2;

        while (DB::table($table)->where($column, $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
