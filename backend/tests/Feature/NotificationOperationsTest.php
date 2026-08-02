<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationRecipient;
use App\Models\IdempotencyKey;
use App\Models\NotificationOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_command_queues_one_job_per_due_recipient_and_recovers_stale_processing(): void
    {
        Queue::fake();
        $this->notification('telegram', '100', NotificationOutbox::STATUS_PENDING);
        $this->notification('telegram', '100', NotificationOutbox::STATUS_PENDING);
        $this->notification('internal', 'admin', NotificationOutbox::STATUS_PROCESSING, [
            'processing_started_at' => now()->subHour(),
        ]);
        $this->notification('telegram', '200', NotificationOutbox::STATUS_PENDING, [
            'available_at' => now()->addHour(),
        ]);

        $this->artisan('notifications:dispatch-outbox')->assertSuccessful();

        Queue::assertPushed(DeliverNotificationRecipient::class, 2);
        Queue::assertPushed(fn (DeliverNotificationRecipient $job): bool => $job->channel === 'telegram' && $job->recipient === '100');
        Queue::assertPushed(fn (DeliverNotificationRecipient $job): bool => $job->channel === 'internal' && $job->recipient === 'admin');
    }

    public function test_failed_notification_requires_an_explicit_retry_scope(): void
    {
        $failed = $this->notification('telegram', '100', NotificationOutbox::STATUS_FAILED, [
            'attempts' => 8,
            'failed_at' => now(),
            'last_error' => 'blocked',
        ]);

        $this->artisan('notifications:retry-failed')->assertFailed();
        $this->artisan('notifications:retry-failed', ['--id' => [$failed->id]])->assertSuccessful();

        $failed->refresh();
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $failed->status);
        $this->assertSame(0, $failed->attempts);
        $this->assertNull($failed->failed_at);
        $this->assertNull($failed->last_error);
    }

    public function test_housekeeping_has_a_dry_run_and_preserves_failures(): void
    {
        IdempotencyKey::query()->create([
            'scope' => 'test',
            'idempotency_key' => 'expired-key',
            'request_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'response_status' => 200,
            'response_body' => ['ok' => true],
            'expires_at' => now()->subDay(),
        ]);
        $this->notification('telegram', '100', NotificationOutbox::STATUS_SENT, [
            'sent_at' => now()->subDays(100),
        ]);
        $this->notification('telegram', '101', NotificationOutbox::STATUS_DISCARDED, [
            'discarded_at' => now()->subDays(40),
        ]);
        $this->notification('telegram', '102', NotificationOutbox::STATUS_FAILED, [
            'failed_at' => now()->subDays(100),
        ]);

        $this->artisan('operations:housekeeping', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('notification_outbox', 3);
        $this->assertDatabaseCount('idempotency_keys', 1);

        $this->artisan('operations:housekeeping')->assertSuccessful();
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertDatabaseHas('notification_outbox', ['status' => NotificationOutbox::STATUS_FAILED]);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_monitor_reports_configuration_and_terminal_failures(): void
    {
        config()->set('notifications.telegram.bot_token', 'token');
        config()->set('notifications.telegram.admin_chat_id', '-100');

        $this->artisan('notifications:monitor')->assertSuccessful();
        $this->notification('telegram', '100', NotificationOutbox::STATUS_FAILED, ['failed_at' => now()]);
        $this->artisan('notifications:monitor')->assertFailed();
    }

    /** @param array<string, mixed> $attributes */
    private function notification(
        string $channel,
        string $recipient,
        string $status,
        array $attributes = [],
    ): NotificationOutbox {
        return NotificationOutbox::query()->create([
            'deduplication_key' => fake()->uuid(),
            'event_type' => 'booking.pending',
            'channel' => $channel,
            'recipient' => $recipient,
            'payload' => ['booking_public_id' => fake()->uuid()],
            'status' => $status,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            ...$attributes,
        ]);
    }
}
