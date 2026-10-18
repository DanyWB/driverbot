<?php

namespace App\Models;

use App\Domain\Pricing\Enums\PricingTier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pricing_preset_id', 'pricing_season_id', 'tier_key', 'package_total'])]
class PricingPresetPrice extends Model
{
    /** @return BelongsTo<PricingPreset, $this> */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(PricingPreset::class, 'pricing_preset_id');
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
            'package_total' => 'integer',
        ];
    }
}
