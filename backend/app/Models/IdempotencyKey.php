<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['service_api_client_id', 'scope', 'idempotency_key', 'request_hash', 'status', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    public function expiresAt(): CarbonImmutable
    {
        $value = $this->getAttribute('expires_at');

        if (! $value instanceof DateTimeInterface) {
            throw new LogicException('Idempotency expiry is not a date-time value.');
        }

        return CarbonImmutable::instance($value);
    }

    /** @return array<string, mixed>|null */
    public function responseBody(): ?array
    {
        $value = $this->getAttribute('response_body');

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new LogicException('Idempotency response body is not an array.');
        }

        return $value;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
