<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string,mixed> $variants
 * @property array<string,string> $alt_text
 */
#[Hidden(['disk', 'original_path', 'original_sha256', 'variants', 'created_by_admin_id'])]
class WebsiteMediaAsset extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['*'];

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['variants' => 'array', 'alt_text' => 'array', 'width' => 'integer', 'height' => 'integer', 'original_bytes' => 'integer'];
    }
}
