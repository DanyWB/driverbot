<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string,mixed> $draft_content
 * @property array<string,mixed>|null $published_content
 * @property int $revision
 * @property int|null $published_revision
 * @property CarbonImmutable|null $published_at
 */
#[Hidden(['draft_content', 'published_content', 'updated_by_admin_id', 'published_by_admin_id'])]
abstract class WebsiteDocument extends Model
{
    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['draft_content' => 'array', 'published_content' => 'array', 'revision' => 'integer',
            'published_revision' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
