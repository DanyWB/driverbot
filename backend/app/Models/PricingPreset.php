<?php

namespace App\Models;

use App\Domain\Vehicles\Enums\VehicleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $calculation_settings
 * @property int $sort_order
 */
#[Fillable(['name', 'vehicle_type', 'version', 'sort_order', 'calculation_settings', 'created_by_admin_id', 'updated_by_admin_id'])]
class PricingPreset extends Model
{
    /** @return HasMany<PricingPresetPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PricingPresetPrice::class);
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'version' => 'integer',
            'sort_order' => 'integer',
            'calculation_settings' => 'array',
        ];
    }
}
