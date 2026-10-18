<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;

trait HandlesAdminContext
{
    protected function fleetReturnTo(Request $request): ?string
    {
        $value = $request->input('return_to');
        if (! is_string($value)) {
            $query = parse_url((string) $request->header('referer'), PHP_URL_QUERY);
            $parameters = [];
            if (is_string($query)) {
                parse_str($query, $parameters);
            }
            $value = $parameters['return_to'] ?? null;
        }

        return is_string($value) && preg_match('~^/vehicles(?:\?[^\r\n#]*)?$~D', $value) === 1
            ? $value : null;
    }

    /** @return array<string, mixed> */
    protected function fleetEditParameters(Request $request, Vehicle $vehicle): array
    {
        return array_filter(['vehicle' => $vehicle, 'return_to' => $this->fleetReturnTo($request)]);
    }

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
