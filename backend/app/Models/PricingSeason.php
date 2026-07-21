<?php

namespace App\Models;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use Database\Factories\PricingSeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'sort_order', 'is_active'])]
class PricingSeason extends Model
{
    /** @use HasFactory<PricingSeasonFactory> */
    use HasFactory;

    /** @return HasMany<PricingSeasonMonth, $this> */
    public function months(): HasMany
    {
        return $this->hasMany(PricingSeasonMonth::class);
    }

    /** @return HasMany<VehiclePriceTier, $this> */
    public function vehiclePriceTiers(): HasMany
    {
        return $this->hasMany(VehiclePriceTier::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key' => PricingSeasonKey::class,
            'is_active' => 'boolean',
        ];
    }
}
