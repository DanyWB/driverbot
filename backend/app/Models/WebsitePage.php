<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsitePage extends WebsiteDocument
{
    /** @return HasMany<WebsitePageRevision,$this> */
    public function history(): HasMany
    {
        return $this->hasMany(WebsitePageRevision::class)->orderByDesc('revision');
    }
}
