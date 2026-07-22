<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'token_hash', 'abilities', 'is_active', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ServiceApiClient extends Model
{
    public function allows(string $ability): bool
    {
        $abilities = $this->getAttribute('abilities');

        return is_array($abilities)
            && (in_array('*', $abilities, true) || in_array($ability, $abilities, true));
    }

    public function lastUsedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('last_used_at');

        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
    }

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
