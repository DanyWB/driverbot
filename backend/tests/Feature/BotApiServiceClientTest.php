<?php

namespace Tests\Feature;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Integrations\Bot\Services\ServiceApiClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotApiServiceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_token_can_be_issued_rotated_and_revoked(): void
    {
        $service = app(ServiceApiClientService::class);
        $issued = $service->issue('Production Telegram bot', [
            'bot:read',
            'bot:write',
            'bot:documents',
        ]);
        $client = $issued['client'];
        $firstToken = $issued['token'];

        $this->assertStringStartsWith('dph_', $firstToken);
        $this->assertSame(hash('sha256', $firstToken), $client->token_hash);
        $this->withToken($firstToken)
            ->getJson('/api/v1/bot/configuration')
            ->assertOk();

        $rotated = $service->rotate($client);
        $secondToken = $rotated['token'];
        $this->assertNotSame($firstToken, $secondToken);
        $this->withToken($firstToken)
            ->getJson('/api/v1/bot/configuration')
            ->assertUnauthorized();
        $this->withToken($secondToken)
            ->getJson('/api/v1/bot/configuration')
            ->assertOk();

        $service->revoke($rotated['client']);
        $this->withToken($secondToken)
            ->getJson('/api/v1/bot/configuration')
            ->assertUnauthorized();
    }

    public function test_service_token_rejects_unknown_abilities(): void
    {
        $this->expectException(BotApiException::class);
        $this->expectExceptionMessage('Service abilities are invalid.');

        app(ServiceApiClientService::class)->issue('Invalid client', ['bot:admin']);
    }
}
