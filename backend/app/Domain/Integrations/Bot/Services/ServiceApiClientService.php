<?php

namespace App\Domain\Integrations\Bot\Services;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Models\ServiceApiClient;

class ServiceApiClientService
{
    /** @param list<string> $abilities
     * @return array{client: ServiceApiClient, token: string}
     */
    public function issue(string $name, array $abilities): array
    {
        $token = $this->token();
        $client = ServiceApiClient::query()->create([
            'name' => trim($name),
            'token_hash' => hash('sha256', $token),
            'abilities' => $this->abilities($abilities),
            'is_active' => true,
        ]);

        return ['client' => $client, 'token' => $token];
    }

    /** @return array{client: ServiceApiClient, token: string} */
    public function rotate(ServiceApiClient $client): array
    {
        $token = $this->token();
        $client->forceFill([
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
            'revoked_at' => null,
        ])->save();

        return ['client' => $client->refresh(), 'token' => $token];
    }

    public function revoke(ServiceApiClient $client): ServiceApiClient
    {
        $client->forceFill(['is_active' => false, 'revoked_at' => now()])->save();

        return $client->refresh();
    }

    /** @param list<string> $abilities
     * @return list<string>
     */
    private function abilities(array $abilities): array
    {
        $allowed = ['bot:read', 'bot:write', 'bot:documents'];
        $abilities = array_values(array_unique(array_map('trim', $abilities)));

        if ($abilities === [] || array_diff($abilities, $allowed) !== []) {
            throw new BotApiException('invalid_service_abilities', 'Service abilities are invalid.', 422, details: [
                'allowed' => $allowed,
            ]);
        }

        sort($abilities);

        return $abilities;
    }

    private function token(): string
    {
        $bytes = max(16, (int) config('bot_api.token_bytes', 32));

        return 'dph_'.bin2hex(random_bytes($bytes));
    }
}
