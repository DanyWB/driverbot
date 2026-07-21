<?php

namespace App\Models;

use App\Domain\Shared\Enums\ActorType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['actor_type', 'actor_admin_id', 'actor_customer_id', 'actor_service_client_id', 'subject_type', 'subject_id', 'action', 'old_values', 'new_values', 'request_id', 'ip_address', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
    }
}
