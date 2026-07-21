<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pricing_season_id', 'month'])]
class PricingSeasonMonth extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<PricingSeason, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(PricingSeason::class, 'pricing_season_id');
    }
}
