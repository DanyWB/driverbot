<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string,mixed> $content
 * @property CarbonImmutable|null $created_at
 */
#[Hidden(['content', 'created_by_admin_id'])]
class WebsitePageRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['content' => 'array', 'revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
