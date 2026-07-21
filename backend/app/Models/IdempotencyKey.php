<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_api_client_id', 'scope', 'idempotency_key', 'request_hash', 'status', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
