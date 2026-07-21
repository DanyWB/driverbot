<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'token_hash', 'abilities', 'is_active', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ServiceApiClient extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
