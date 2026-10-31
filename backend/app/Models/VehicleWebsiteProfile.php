<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string,mixed> $draft_content
 * @property array<string,mixed>|null $published_content
 * @property array<string,mixed>|null $published_cover_manifest
 * @property int $revision
 * @property int|null $published_revision
 * @property CarbonImmutable|null $published_at
 */
#[Hidden(['draft_content', 'draft_cover_asset_id', 'published_content', 'published_cover_manifest', 'updated_by_admin_id', 'published_by_admin_id'])]
class VehicleWebsiteProfile extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<WebsiteMediaAsset, $this> */
    public function draftCover(): BelongsTo
    {
        return $this->belongsTo(WebsiteMediaAsset::class, 'draft_cover_asset_id');
    }

    /** @return BelongsTo<WebsiteMediaAsset, $this> */
    public function publishedCover(): BelongsTo
    {
        return $this->belongsTo(WebsiteMediaAsset::class, 'published_cover_asset_id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['draft_content' => 'array', 'published_content' => 'array', 'published_cover_manifest' => 'array', 'revision' => 'integer',
            'published_revision' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
