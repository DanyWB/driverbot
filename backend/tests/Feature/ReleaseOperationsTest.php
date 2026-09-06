<?php

namespace Tests\Feature;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Operations\Services\ReleasePreflightService;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_strict_release_preflight_reports_missing_production_requirements_without_secrets(): void
    {
        $secretMarker = 'do-not-print-this-token-value';
        config()->set('notifications.telegram.bot_token', '123456789:'.$secretMarker.str_repeat('a', 35));

        $checks = app(ReleasePreflightService::class)->inspect(true);

        $this->assertContains('app.environment', collect($checks)->pluck('id')->all());
        $this->assertStringNotContainsString(
            $secretMarker,
            collect($checks)->pluck('message')->implode(' '),
        );

        $this->artisan('operations:release-preflight', ['--strict' => true, '--json' => true])
            ->expectsOutputToContain('"passed": false')
            ->doesntExpectOutputToContain($secretMarker)
            ->assertFailed();
    }

    public function test_scheduler_heartbeat_is_recorded_and_can_gate_readiness(): void
    {
        config()->set('health.scheduler', true);

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler', 'failed');

        $this->artisan('operations:scheduler-heartbeat')->assertSuccessful();

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.scheduler', 'ok');

        $this->assertTrue(app(SchedulerHeartbeat::class)->isFresh());
    }

    public function test_strict_release_preflight_fails_when_an_active_admin_lacks_two_factor(): void
    {
        User::factory()->create(['two_factor_confirmed_at' => null]);
        $preflight = app(ReleasePreflightService::class);

        $strictCheck = collect($preflight->inspect(true))->firstWhere('id', 'admin.accounts');
        $advisoryCheck = collect($preflight->inspect(false))->firstWhere('id', 'admin.accounts');

        $this->assertInstanceOf(ReleaseCheck::class, $strictCheck);
        $this->assertSame(ReleaseCheck::FAILURE, $strictCheck->status);
        $this->assertInstanceOf(ReleaseCheck::class, $advisoryCheck);
        $this->assertSame(ReleaseCheck::WARNING, $advisoryCheck->status);
    }

    public function test_strict_release_preflight_fails_when_an_active_admin_email_is_unverified(): void
    {
        User::factory()->unverified()->withTwoFactor()->create();
        $preflight = app(ReleasePreflightService::class);

        $strictCheck = collect($preflight->inspect(true))->firstWhere('id', 'admin.accounts');
        $advisoryCheck = collect($preflight->inspect(false))->firstWhere('id', 'admin.accounts');

        $this->assertInstanceOf(ReleaseCheck::class, $strictCheck);
        $this->assertSame(ReleaseCheck::FAILURE, $strictCheck->status);
        $this->assertInstanceOf(ReleaseCheck::class, $advisoryCheck);
        $this->assertSame(ReleaseCheck::WARNING, $advisoryCheck->status);
    }
}
