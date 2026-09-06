<?php

namespace Tests\Feature;

use App\Domain\Notifications\Exceptions\NotificationNotApplicableException;
use App\Domain\Notifications\Services\NotificationDeliveryService;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Domain\Notifications\Services\TelegramNotificationRenderer;
use App\Models\AdminTelegramBinding;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTelegramNotificationFanoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_event_fans_out_to_every_connected_active_administrator(): void
    {
        config()->set('notifications.telegram.admin_chat_id', '999999');
        $first = $this->binding('100001', 'ru');
        $second = $this->binding('100002', 'en');

        $notifications = app(NotificationOutboxService::class)->enqueueAdmins(
            'booking.pending',
            'booking:test:event:admin',
            ['booking_public_id' => fake()->uuid()],
        );

        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [$first->recipientKey(), $second->recipientKey()],
            collect($notifications)->pluck('recipient')->all(),
        );
        $this->assertNotContains('admin', collect($notifications)->pluck('recipient')->all());
        $this->assertEqualsCanonicalizing(
            ['ru', 'en'],
            collect($notifications)->pluck('payload.admin_locale')->all(),
        );
    }

    public function test_tombstone_prevents_legacy_fallback_from_reappearing(): void
    {
        config()->set('notifications.telegram.admin_chat_id', '999999');
        AdminTelegramBinding::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'generation' => 2,
            'disconnected_at' => now(),
        ]);

        $notifications = app(NotificationOutboxService::class)->enqueueAdmins(
            'booking.pending',
            'booking:test:no-recipient:admin',
            ['booking_public_id' => fake()->uuid()],
        );

        $this->assertSame([], $notifications);
        $this->assertDatabaseCount('notification_outbox', 0);
    }

    public function test_a_queued_legacy_notification_is_discarded_after_database_mode_starts(): void
    {
        config()->set('notifications.telegram.bot_token', 'test-token');
        config()->set('notifications.telegram.admin_chat_id', '100099');
        $this->binding('100100', 'ru');
        $notification = NotificationOutbox::query()->create([
            'deduplication_key' => 'booking:test:legacy-before-binding',
            'event_type' => 'booking.pending',
            'channel' => 'internal',
            'recipient' => 'admin',
            'payload' => ['booking_public_id' => $this->booking()->public_id],
            'status' => NotificationOutbox::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
        ]);
        Http::fake();

        $this->assertSame(
            0,
            app(NotificationDeliveryService::class)->deliverRecipient('internal', 'admin'),
        );
        $this->assertSame(NotificationOutbox::STATUS_DISCARDED, $notification->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_binding_generation_is_discarded_without_contacting_telegram(): void
    {
        config()->set('notifications.telegram.bot_token', 'test-token');
        $binding = $this->binding('100003', 'en');
        $booking = $this->booking();
        $notification = app(NotificationOutboxService::class)->enqueueAdmins(
            'booking.pending',
            'booking:test:stale:admin',
            ['booking_public_id' => $booking->public_id],
        )[0];

        $binding->forceFill([
            'telegram_user_id' => null,
            'telegram_chat_id' => null,
            'username' => null,
            'first_name' => null,
            'last_name' => null,
            'connected_at' => null,
            'disconnected_at' => now(),
            'generation' => $binding->generation + 1,
        ])->save();
        Http::fake();

        $this->assertSame(
            0,
            app(NotificationDeliveryService::class)->deliverRecipient('internal', (string) $notification->recipient),
        );
        $this->assertSame(NotificationOutbox::STATUS_DISCARDED, $notification->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_each_administrator_has_independent_delivery_and_locale(): void
    {
        config()->set('notifications.telegram.bot_token', 'test-token');
        config()->set('app.url', 'https://admin.example.test');
        $first = $this->binding('100004', 'ru');
        $second = $this->binding('100005', 'en');
        $booking = $this->booking();
        $notifications = app(NotificationOutboxService::class)->enqueueAdmins(
            'booking.pending',
            'booking:test:independent:admin',
            ['booking_public_id' => $booking->public_id],
        );
        Http::fake(function (Request $request) {
            if ($request['chat_id'] === '100004') {
                return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Blocked'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200);
        });
        $delivery = app(NotificationDeliveryService::class);

        $delivery->deliverRecipient('internal', $first->recipientKey());
        $delivery->deliverRecipient('internal', $second->recipientKey());

        $firstNotification = collect($notifications)->firstWhere('recipient', $first->recipientKey());
        $secondNotification = collect($notifications)->firstWhere('recipient', $second->recipientKey());
        $this->assertInstanceOf(NotificationOutbox::class, $firstNotification);
        $this->assertInstanceOf(NotificationOutbox::class, $secondNotification);
        $this->assertSame(NotificationOutbox::STATUS_FAILED, $firstNotification->fresh()->status);
        $this->assertSame(NotificationOutbox::STATUS_SENT, $secondNotification->fresh()->status);

        $english = app(TelegramNotificationRenderer::class)->render(
            NotificationOutbox::query()->create([
                'deduplication_key' => fake()->uuid(),
                'event_type' => 'booking.pending',
                'channel' => 'internal',
                'recipient' => $second->recipientKey(),
                'payload' => ['booking_public_id' => $booking->public_id],
                'status' => NotificationOutbox::STATUS_PENDING,
                'available_at' => now(),
            ]),
        );
        $this->assertSame('100005', $english->chatId);
        $this->assertStringContainsString('New request', $english->text);
    }

    public function test_renderer_rejects_an_unknown_admin_binding_alias(): void
    {
        $this->expectException(NotificationNotApplicableException::class);

        app(TelegramNotificationRenderer::class)->render(NotificationOutbox::query()->create([
            'deduplication_key' => fake()->uuid(),
            'event_type' => 'booking.pending',
            'channel' => 'internal',
            'recipient' => 'admin-binding:999:v1',
            'payload' => ['booking_public_id' => $this->booking()->public_id],
            'status' => NotificationOutbox::STATUS_PENDING,
            'available_at' => now(),
        ]));
    }

    private function binding(string $telegramId, string $locale): AdminTelegramBinding
    {
        return AdminTelegramBinding::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'username' => "admin{$telegramId}",
            'first_name' => 'Admin',
            'locale' => $locale,
            'generation' => 1,
            'connected_at' => now(),
        ]);
    }

    private function booking(): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Test customer'])->id,
            'vehicle_id' => Vehicle::factory()->create(['name' => 'Test vehicle'])->id,
        ]);
    }
}
