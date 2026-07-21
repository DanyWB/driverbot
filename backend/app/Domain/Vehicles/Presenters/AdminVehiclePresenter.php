<?php

namespace App\Domain\Vehicles\Presenters;

use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Vehicles\Services\VehiclePhotoService;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;

class AdminVehiclePresenter
{
    public function __construct(
        private readonly VehiclePhotoService $photos,
        private readonly VehiclePricingService $pricing,
    ) {}

    /** @return array<string, mixed> */
    public function listItem(Vehicle $vehicle): array
    {
        $primary = $vehicle->photos->firstWhere('is_primary', true) ?? $vehicle->photos->first();
        $activePriceCount = (int) ($vehicle->getAttribute('active_price_tiers_count') ?? 0);

        return [
            'id' => (int) $vehicle->id,
            'external_code' => (string) $vehicle->external_code,
            'name' => (string) $vehicle->name,
            'type' => (string) $vehicle->getRawOriginal('type'),
            'inventory_code' => $this->nullableString($vehicle->inventory_code),
            'year' => is_numeric($vehicle->year) ? (int) $vehicle->year : null,
            'category' => $vehicle->category === null ? null : [
                'id' => (int) $vehicle->category->id,
                'name' => (string) $vehicle->category->name,
                'is_active' => (bool) $vehicle->category->is_active,
            ],
            'is_active' => (bool) $vehicle->is_active,
            'is_visible_for_booking' => (bool) $vehicle->is_visible_for_booking,
            'sort_order' => (int) $vehicle->sort_order,
            'active_price_tiers_count' => $activePriceCount,
            'has_complete_pricing' => $activePriceCount === 15,
            'photos_count' => (int) ($vehicle->getAttribute('photos_count') ?? $vehicle->photos->count()),
            'bookings_count' => (int) ($vehicle->getAttribute('bookings_count') ?? 0),
            'primary_photo' => $primary instanceof VehiclePhoto ? $this->photo($primary) : null,
            'updated_at' => $vehicle->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Vehicle $vehicle): array
    {
        return [
            ...$this->listItem($vehicle),
            'description' => $this->nullableString($vehicle->description),
            'characteristics_text' => $this->nullableString($vehicle->characteristics_text),
            'emoji' => $this->nullableString($vehicle->emoji),
            'pricing_profile' => $this->nullableString($vehicle->pricing_profile),
            'photos' => $vehicle->photos->map(fn (VehiclePhoto $photo): array => $this->photo($photo))->values()->all(),
            'pricing' => $this->pricing->matrix($vehicle),
        ];
    }

    /** @return array<string, mixed> */
    private function photo(VehiclePhoto $photo): array
    {
        return [
            'id' => (int) $photo->id,
            'url' => $this->photos->url($photo),
            'thumbnail_url' => $this->photos->thumbnailUrl($photo),
            'alt_text' => $this->nullableString($photo->alt_text),
            'sort_order' => (int) $photo->sort_order,
            'is_primary' => (bool) $photo->is_primary,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
