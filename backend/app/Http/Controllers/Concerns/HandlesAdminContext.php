<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait HandlesAdminContext
{
    protected function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function requestId(Request $request): ?string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) ? $requestId : null;
    }

    protected function userAgent(Request $request): ?string
    {
        $userAgent = $request->userAgent();

        return is_string($userAgent) ? $userAgent : null;
    }
}
