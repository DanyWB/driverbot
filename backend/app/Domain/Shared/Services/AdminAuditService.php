<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        User $admin,
        Model $subject,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_type' => ActorType::Admin,
            'actor_admin_id' => $admin->id,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getRouteKey(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
