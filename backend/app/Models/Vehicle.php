<?php

namespace App\Models;

use App\Domain\Vehicles\Enums\VehicleType;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $archived_at Lifecycle timestamp, intentionally excluded from mass assignment.
 */
#[Fillable(['external_code', 'type', 'category_id', 'name', 'inventory_code', 'year', 'description', 'description_ru', 'description_ua', 'characteristics_text', 'emoji', 'is_active', 'is_visible_for_booking', 'sort_order', 'pricing_profile', 'source_sheet', 'source_row', 'source_import_run_id', 'imported_at'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<PricingPreset, $this> */
    public function pricingPreset(): BelongsTo
    {
        return $this->belongsTo(PricingPreset::class);
    }

    /** @return HasMany<VehiclePhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(VehiclePhoto::class)->orderBy('sort_order');
    }

    /** @return HasMany<VehiclePriceTier, $this> */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(VehiclePriceTier::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<Booking, $this> */
    public function unfinishedBookings(): HasMany
    {
        return $this->bookings()->whereIn('status', ['process', 'pending', 'approved', 'active']);
    }

    /** @return HasMany<VehicleOccupancy, $this> */
    public function occupancies(): HasMany
    {
        return $this->hasMany(VehicleOccupancy::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'is_active' => 'boolean',
            'is_visible_for_booking' => 'boolean',
            'pricing_version' => 'integer',
            'imported_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
