<?php

namespace Tests\Feature;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingStatusMutationGuard;
use App\Domain\Notifications\Services\NotificationDeliveryService;
use App\Domain\Notifications\Services\TelegramNotificationRenderer;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\Customer;
use App\Models\NotificationOutbox;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class NotificationOutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDeliveryService $delivery;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('notifications.telegram.bot_token', 'test-token');
        config()->set('notifications.telegram.admin_chat_id', '-100500');
        config()->set('notifications.telegram.admin_locale', 'ru');
        config()->set('notifications.outbox.backoff_seconds', [60, 300]);
        config()->set('notifications.outbox.max_attempts', 3);
        config()->set('app.url', 'https://admin.example.test');

        $customer = Customer::factory()->create(['name' => 'Alice <script>alert(1)</script>', 'locale' => 'en']);
        $vehicle = Vehicle::factory()->create(['name' => 'Honda <b>ADV</b>']);
        $this->booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-08-22',
            'pickup_time' => '10:00:00',
            'return_time' => '12:00:00',
        ]);
        BookingPriceSnapshot::query()->create([
            'booking_id' => $this->booking->id,
            'version' => 1,
            'total_days' => 3,
            'tier_key' => '1d',
            'calculated_total' => 900,
            'rounded_total' => 900,
            'final_total' => 900,
            'currency' => 'THB',
            'breakdown' => [],
            'pricing_source' => 'automatic',
            'calculated_at' => now(),
        ]);
        $this->delivery = app(NotificationDeliveryService::class);
    }

    public function test_customer_notification_is_escaped_delivered_and_audited(): void
    {
        $notification = $this->notification('booking.pending', 'telegram', '100500');
        Http::fake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ]),
        ]);

        $this->assertSame(1, $this->delivery->deliverRecipient('telegram', '100500'));

        $notification->refresh();
        $this->assertSame(NotificationOutbox::STATUS_SENT, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('321', $notification->provider_message_id);
        $this->assertNotNull($notification->sent_at);
        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            return $request['chat_id'] === '100500'
                && str_contains($text, 'Request #')
                && str_contains($text, 'Honda &lt;b&gt;ADV&lt;/b&gt;')
                && ! str_contains($text, '<script>')
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'rent:details:'.$this->booking->public_id;
        });
    }

    public function test_admin_alias_resolves_to_configured_chat_and_admin_url(): void
    {
        $notification = $this->notification('booking.pending', 'internal', 'admin');
        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        ]);

        $this->delivery->deliverRecipient('internal', 'admin');

        $this->assertSame(NotificationOutbox::STATUS_SENT, $notification->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '-100500'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.url') === 'https://admin.example.test/bookings/'.$this->booking->public_id);
    }

    public function test_admin_expired_notification_has_supported_localized_templates(): void
    {
        $notification = $this->notification('booking.expired', 'internal', 'admin');
        $renderer = app(TelegramNotificationRenderer::class);

        foreach ([
            'ru' => ['Срок заявки', 'техника освобождена'],
            'en' => ['Booking request', 'vehicle was released'],
            'ua' => ['Термін заявки', 'транспорт звільнено'],
        ] as $locale => $fragments) {
            config()->set('notifications.telegram.admin_locale', $locale);
            $message = $renderer->render($notification);

            $this->assertSame('-100500', $message->chatId);
            $this->assertStringContainsString($fragments[0], $message->text);
            $this->assertStringContainsString($fragments[1], $message->text);
        }
    }

    public function test_cancelled_notification_escapes_reason_and_omits_the_heading_when_reason_is_empty(): void
    {
        $this->booking->forceFill([
            'cancellation_reason' => 'Unavailable <b>today</b> & tomorrow',
        ])->save();
        $renderer = app(TelegramNotificationRenderer::class);
        $withReason = $renderer->render($this->notification('booking.cancelled', 'telegram', '100500'));

        $this->assertStringContainsString(
            'Reason: Unavailable &lt;b&gt;today&lt;/b&gt; &amp; tomorrow',
            $withReason->text,
        );
        $this->assertStringNotContainsString('Unavailable <b>today</b>', $withReason->text);

        $this->booking->forceFill(['cancellation_reason' => null])->save();
        $withoutReason = $renderer->render($this->notification('booking.cancelled', 'telegram', '100501'));

        $this->assertStringNotContainsString('Reason:', $withoutReason->text);
    }

    public function test_cancelled_notification_preserves_valid_html_for_a_long_reason_of_special_characters(): void
    {
        $reason = str_repeat('&<>', 666);
        $this->booking->forceFill(['cancellation_reason' => $reason])->save();
        Lang::addLines([
            'notifications.customer.cancelled_with_reason' => '<b>Reason: :reason '.str_repeat('x', 3000).'</b>',
        ], 'en');

        $message = app(TelegramNotificationRenderer::class)->render(
            $this->notification('booking.cancelled', 'telegram', '100500'),
        );
        $visibleText = html_entity_decode(strip_tags($message->text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertGreaterThan(4096, mb_strlen($message->text));
        $this->assertSame(4096, mb_strlen($visibleText));
        $this->assertStringContainsString($reason, $visibleText);
        $this->assertStringStartsWith('<b>', $message->text);
        $this->assertStringEndsWith('…</b>', $message->text);
        $this->assertSame(substr_count($reason, '&'), substr_count($message->text, '&amp;'));
        $this->assertSame(substr_count($reason, '<'), substr_count($message->text, '&lt;'));
        $this->assertSame(substr_count($reason, '>'), substr_count($message->text, '&gt;'));
    }

    public function test_temporary_failure_uses_backoff_and_is_delivered_after_retry(): void
    {
        $notification = $this->notification('booking.approved', 'telegram', '100500');
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 503, 'description' => 'Unavailable'], 503)
            ->push(['ok' => true, 'result' => ['message_id' => 400]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 401]], 200);

        $this->assertSame(0, $this->delivery->deliverRecipient('telegram', '100500'));
        $notification->refresh();
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertTrue($notification->available_at->isFuture());

        $newer = $this->notification('booking.price_changed', 'telegram', '100500', [
            'final_total' => 1200,
            'currency' => 'THB',
        ]);
        $this->assertSame(0, $this->delivery->deliverRecipient('telegram', '100500'));
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $newer->fresh()->status);
        Http::assertSentCount(1);

        $notification->forceFill(['available_at' => now()->subSecond()])->save();
        $this->assertSame(2, $this->delivery->deliverRecipient('telegram', '100500'));
        $this->assertSame(NotificationOutbox::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame(NotificationOutbox::STATUS_SENT, $newer->fresh()->status);
        $this->assertSame(2, $notification->fresh()->attempts);
        Http::assertSentCount(3);
    }

    public function test_permanent_telegram_error_is_terminal_until_manual_retry(): void
    {
        $notification = $this->notification('booking.cancelled', 'telegram', '100500');
        Http::fake([
            '*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Bot was blocked'], 403),
        ]);

        $this->delivery->deliverRecipient('telegram', '100500');

        $notification->refresh();
        $this->assertSame(NotificationOutbox::STATUS_FAILED, $notification->status);
        $this->assertNotNull($notification->failed_at);
        $this->assertStringContainsString('403', (string) $notification->last_error);
    }

    public function test_superseded_reminder_is_discarded_without_an_http_request(): void
    {
        app(BookingStatusMutationGuard::class)->run(function (): void {
            $this->booking->forceFill([
                'status' => BookingStatus::Approved,
                'reminder_version' => 2,
            ])->save();
        });
        $notification = $this->notification('booking.reminder.pickup_day', 'telegram', '100500', [
            'reminder_version' => 1,
            'starts_on' => '2026-08-20',
            'pickup_time' => '10:00:00',
        ]);
        Http::fake();

        $this->assertSame(0, $this->delivery->deliverRecipient('telegram', '100500'));

        $this->assertSame(NotificationOutbox::STATUS_DISCARDED, $notification->fresh()->status);
        $this->assertNotNull($notification->fresh()->discarded_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $payload */
    private function notification(
        string $eventType,
        string $channel,
        string $recipient,
        array $payload = [],
    ): NotificationOutbox {
        return NotificationOutbox::query()->create([
            'deduplication_key' => fake()->uuid(),
            'event_type' => $eventType,
            'channel' => $channel,
            'recipient' => $recipient,
            'payload' => [
                'booking_public_id' => $this->booking->public_id,
                ...$payload,
            ],
            'status' => NotificationOutbox::STATUS_PENDING,
            'available_at' => now()->subSecond(),
        ]);
    }
}
