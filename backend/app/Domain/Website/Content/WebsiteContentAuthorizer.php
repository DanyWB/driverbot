<?php

namespace App\Domain\Website\Content;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class WebsiteContentAuthorizer
{
    public function authorize(User $actor, bool $lock = false): User
    {
        $query = User::query()->whereKey($actor->getKey())->where('is_active', true)
            ->where('role', 'full_admin')->whereNotNull('email_verified_at');
        $admin = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $actor->exists || $admin === null) {
            throw new AuthorizationException('Website authoring requires an active verified full administrator.');
        }

        return $admin;
    }
}
