<?php

namespace App\Models;

use App\Domain\Pricing\Enums\PricingTier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'pricing_season_id', 'tier_key', 'min_days', 'max_days', 'anchor_days', 'package_total', 'daily_rate', 'currency', 'is_active', 'source_sheet', 'source_row'])]
class VehiclePriceTier extends Model
{
    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<PricingSeason, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(PricingSeason::class, 'pricing_season_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier_key' => PricingTier::class,
            'daily_rate' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }
}
