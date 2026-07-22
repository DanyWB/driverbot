<?php

namespace App\Http\Middleware;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Models\ServiceApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateServiceApiClient
{
    public function handle(Request $request, Closure $next, string ...$requiredAbilities): Response
    {
        $this->assertAuthenticationAllowed($request);
        $token = trim((string) $request->bearerToken());

        if ($token === '' || strlen($token) > 255) {
            $this->reject($request);
        }

        $client = ServiceApiClient::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $client instanceof ServiceApiClient
            || ! $client->is_active
            || $client->revoked_at !== null) {
            $this->reject($request);
        }

        foreach ($requiredAbilities as $ability) {
            if (! $client->allows($ability)) {
                throw new BotApiException('ability_forbidden', 'The service token cannot perform this operation.', 403, details: [
                    'required_ability' => $ability,
                ]);
            }
        }

        $lastUsedAt = $client->lastUsedAt();

        if ($lastUsedAt === null || $lastUsedAt->lt(now()->subMinutes(5))) {
            ServiceApiClient::query()->whereKey($client->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('service_api_client', $client);

        return $next($request);
    }

    private function reject(Request $request): never
    {
        RateLimiter::hit($this->authenticationKey($request), 60);

        throw new BotApiException('unauthenticated', 'A valid service bearer token is required.', 401);
    }

    private function assertAuthenticationAllowed(Request $request): void
    {
        $limit = max(1, (int) config('bot_api.auth_rate_limit_per_minute', 30));

        if (RateLimiter::tooManyAttempts($this->authenticationKey($request), $limit)) {
            throw new BotApiException('rate_limit_exceeded', 'Too many authentication attempts.', 429);
        }
    }

    private function authenticationKey(Request $request): string
    {
        return 'bot-api-auth:'.($request->ip() ?: 'unknown');
    }
}
