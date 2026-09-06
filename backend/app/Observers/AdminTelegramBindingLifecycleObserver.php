<?php

namespace App\Observers;

use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Models\User;

class AdminTelegramBindingLifecycleObserver
{
    public function __construct(
        private readonly AdminTelegramBindingService $bindings,
    ) {}

    public function updated(User $admin): void
    {
        $becameInactive = $admin->wasChanged('is_active') && ! $admin->is_active;
        $lostVerification = $admin->wasChanged('email_verified_at')
            && $admin->email_verified_at === null;

        if ($becameInactive || $lostVerification) {
            $this->bindings->disconnect($admin);
        }
    }

    public function deleting(User $admin): void
    {
        $this->bindings->disconnect($admin);
    }
}
