<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Bot\Services\ServiceApiClientService;
use App\Models\ServiceApiClient;
use Illuminate\Console\Command;

class ManageBotApiClient extends Command
{
    protected $signature = 'bot-api:client
        {action : issue, rotate or revoke}
        {--id= : Existing service client ID for rotate/revoke}
        {--name=Telegram Bot : Name for a new client}
        {--abilities=bot:read,bot:write,bot:documents : Comma-separated abilities}';

    protected $description = 'Issue, rotate or revoke a Telegram Bot API service token';

    public function handle(ServiceApiClientService $clients): int
    {
        $actionArgument = $this->argument('action');
        $action = strtolower(is_string($actionArgument) ? $actionArgument : '');

        if ($action === 'issue') {
            $nameOption = $this->option('name');
            $abilitiesOption = $this->option('abilities');
            $result = $clients->issue(
                is_string($nameOption) ? $nameOption : '',
                array_values(array_filter(explode(',', is_string($abilitiesOption) ? $abilitiesOption : ''))),
            );
            $this->showToken((int) $result['client']->id, $result['token']);

            return self::SUCCESS;
        }

        $idOption = $this->option('id');
        $client = is_string($idOption) || is_int($idOption)
            ? ServiceApiClient::query()->find($idOption)
            : null;

        if (! $client instanceof ServiceApiClient) {
            $this->error('A valid --id is required for rotate/revoke.');

            return self::FAILURE;
        }

        if ($action === 'rotate') {
            $result = $clients->rotate($client);
            $this->showToken((int) $result['client']->id, $result['token']);

            return self::SUCCESS;
        }

        if ($action === 'revoke') {
            $clients->revoke($client);
            $this->info("Service client {$client->id} revoked.");

            return self::SUCCESS;
        }

        $this->error('Action must be issue, rotate or revoke.');

        return self::INVALID;
    }

    private function showToken(int $clientId, string $token): void
    {
        $this->warn('Store this token now. It will not be shown again.');
        $this->line("Client ID: {$clientId}");
        $this->line("Token: {$token}");
    }
}
