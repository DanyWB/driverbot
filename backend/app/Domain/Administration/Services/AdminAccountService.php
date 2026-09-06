<?php

namespace App\Domain\Administration\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAccountService
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $activeAdministrators = User::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedUser = $activeAdministrators->firstWhere('id', $user->getKey());

            if (! $lockedUser instanceof User) {
                throw ValidationException::withMessages([
                    'password' => 'This administrator account is no longer active.',
                ]);
            }

            if ($activeAdministrators->count() <= 1) {
                throw ValidationException::withMessages([
                    'password' => 'The last active administrator account cannot be deleted.',
                ]);
            }

            $lockedUser->delete();
        }, 3);
    }
}
