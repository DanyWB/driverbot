<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_liveness_endpoint_is_available(): void
    {
        $this->get('/health/live')
            ->assertOk()
            ->assertHeader('X-Request-ID');
    }

    public function test_readiness_endpoint_checks_required_dependencies(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJson([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'redis' => 'skipped',
                    'scheduler' => 'skipped',
                ],
            ]);

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_valid_request_id_is_preserved(): void
    {
        $this->withHeader('X-Request-ID', 'test-request-123')
            ->get('/health/live')
            ->assertHeader('X-Request-ID', 'test-request-123');
    }
}
