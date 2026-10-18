<?php

namespace Tests\Feature;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Operations\Services\ReleasePreflightService;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Domain\Vehicles\Services\TelegramPhotoOptimizer;
use App\Models\AdminTelegramBinding;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
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

        $accountsCheck = collect($preflight->inspect(true))->firstWhere('id', 'admin.accounts');
        $strictCheck = collect($preflight->inspect(true))->firstWhere('id', 'admin.two_factor');
        $advisoryCheck = collect($preflight->inspect(false))->firstWhere('id', 'admin.two_factor');

        $this->assertInstanceOf(ReleaseCheck::class, $accountsCheck);
        $this->assertSame(ReleaseCheck::PASS, $accountsCheck->status);
        $this->assertInstanceOf(ReleaseCheck::class, $strictCheck);
        $this->assertSame(ReleaseCheck::FAILURE, $strictCheck->status);
        $this->assertInstanceOf(ReleaseCheck::class, $advisoryCheck);
        $this->assertSame(ReleaseCheck::WARNING, $advisoryCheck->status);
    }

    public function test_release_preflight_is_strict_by_default_and_reports_no_accepted_risks(): void
    {
        $exitCode = Artisan::call('operations:release-preflight', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['strict']);
        $this->assertSame([], $payload['accepted_risks']);
        $this->assertNull($payload['risk_reason']);
        $environment = collect($payload['checks'])->firstWhere('id', 'app.environment');
        $this->assertIsArray($environment);
        $this->assertSame(ReleaseCheck::FAILURE, $environment['status']);
    }

    public function test_release_preflight_accepts_only_supported_risks_and_preserves_other_failures(): void
    {
        $reason = 'SMTP is explicitly deferred for this one deployment.';
        $exitCode = Artisan::call('operations:release-preflight', [
            '--json' => true,
            '--accept-risk' => ['mail.transport'],
            '--risk-reason' => $reason,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(['mail.transport'], $payload['accepted_risks']);
        $this->assertSame($reason, $payload['risk_reason']);
        $mail = collect($payload['checks'])->firstWhere('id', 'mail.transport');
        $environment = collect($payload['checks'])->firstWhere('id', 'app.environment');
        $this->assertIsArray($mail);
        $this->assertSame(ReleaseCheck::WARNING, $mail['status']);
        $this->assertStringContainsString($reason, $mail['message']);
        $this->assertIsArray($environment);
        $this->assertSame(ReleaseCheck::FAILURE, $environment['status']);
    }

    public function test_release_preflight_rejects_unknown_risks_and_missing_reasons(): void
    {
        $unknownExit = Artisan::call('operations:release-preflight', [
            '--json' => true,
            '--accept-risk' => ['admin.accounts'],
            '--risk-reason' => 'Must not bypass administrator identity requirements.',
        ]);
        $unknown = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Command::INVALID, $unknownExit);
        $this->assertIsArray($unknown);
        $this->assertStringContainsString('Unsupported release risk IDs', $unknown['error']);

        $missingReasonExit = Artisan::call('operations:release-preflight', [
            '--json' => true,
            '--accept-risk' => ['admin.two_factor'],
            '--risk-reason' => '   ',
        ]);
        $missingReason = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Command::INVALID, $missingReasonExit);
        $this->assertIsArray($missingReason);
        $this->assertStringContainsString('--risk-reason must be non-empty', $missingReason['error']);
    }

    public function test_accepting_two_factor_risk_does_not_bypass_unverified_admin_accounts(): void
    {
        User::factory()->unverified()->create(['two_factor_confirmed_at' => null]);
        $reason = 'Two-factor enrollment is deferred for this one deployment.';

        $exitCode = Artisan::call('operations:release-preflight', [
            '--json' => true,
            '--accept-risk' => ['admin.two_factor'],
            '--risk-reason' => $reason,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $accounts = collect($payload['checks'])->firstWhere('id', 'admin.accounts');
        $twoFactor = collect($payload['checks'])->firstWhere('id', 'admin.two_factor');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertIsArray($accounts);
        $this->assertSame(ReleaseCheck::FAILURE, $accounts['status']);
        $this->assertIsArray($twoFactor);
        $this->assertSame(ReleaseCheck::WARNING, $twoFactor['status']);
        $this->assertStringContainsString($reason, $twoFactor['message']);
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

    public function test_release_preflight_accepts_an_active_database_telegram_binding(): void
    {
        config()->set('notifications.telegram.bot_token', '123456789:'.str_repeat('a', 35));
        config()->set('notifications.telegram.bot_username', 'DrivePhangan_TestBot');
        config()->set('notifications.telegram.admin_chat_id', null);
        $admin = User::factory()->create();
        AdminTelegramBinding::query()->create([
            'admin_user_id' => $admin->id,
            'telegram_user_id' => '700300',
            'telegram_chat_id' => '700300',
            'locale' => 'ru',
            'generation' => 1,
            'connected_at' => now(),
        ]);

        $check = collect(app(ReleasePreflightService::class)->inspect(true))
            ->firstWhere('id', 'telegram.configuration');

        $this->assertInstanceOf(ReleaseCheck::class, $check);
        $this->assertSame(ReleaseCheck::PASS, $check->status);
    }

    public function test_release_preflight_does_not_restore_bootstrap_recipient_after_disconnect(): void
    {
        config()->set('notifications.telegram.bot_token', '123456789:'.str_repeat('a', 35));
        config()->set('notifications.telegram.bot_username', 'DrivePhangan_TestBot');
        config()->set('notifications.telegram.admin_chat_id', '700301');
        AdminTelegramBinding::query()->create([
            'generation' => 2,
            'connected_at' => now()->subHour(),
            'disconnected_at' => now(),
        ]);

        $check = collect(app(ReleasePreflightService::class)->inspect(true))
            ->firstWhere('id', 'telegram.configuration');

        $this->assertInstanceOf(ReleaseCheck::class, $check);
        $this->assertSame(ReleaseCheck::FAILURE, $check->status);
    }

    public function test_release_preflight_requires_telegram_copies_for_every_published_vehicle_photo(): void
    {
        Storage::fake('public');
        $vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        $primary = VehiclePhoto::query()->create([
            'vehicle_id' => $vehicle->id,
            'disk' => 'public',
            'file_path' => "vehicles/{$vehicle->id}/primary.jpg",
            'sort_order' => 0,
            'is_primary' => true,
        ]);
        $secondary = VehiclePhoto::query()->create([
            'vehicle_id' => $vehicle->id,
            'disk' => 'public',
            'file_path' => "vehicles/{$vehicle->id}/secondary.jpg",
            'sort_order' => 1,
            'is_primary' => false,
        ]);
        $optimizer = app(TelegramPhotoOptimizer::class);
        Storage::disk('public')->put($optimizer->path($primary->file_path), 'telegram-ready');

        $check = collect(app(ReleasePreflightService::class)->inspect(true))
            ->firstWhere('id', 'catalog.telegram_photos');
        $this->assertInstanceOf(ReleaseCheck::class, $check);
        $this->assertSame(ReleaseCheck::FAILURE, $check->status);
        $this->assertStringContainsString('without a Telegram delivery copy: 1', $check->message);

        Storage::disk('public')->put($optimizer->path($secondary->file_path), 'telegram-ready');
        $check = collect(app(ReleasePreflightService::class)->inspect(true))
            ->firstWhere('id', 'catalog.telegram_photos');
        $this->assertInstanceOf(ReleaseCheck::class, $check);
        $this->assertSame(ReleaseCheck::PASS, $check->status);
    }
}
