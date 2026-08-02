<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Exceptions\NotificationDeliveryException;
use App\Domain\Notifications\Exceptions\NotificationNotApplicableException;
use App\Models\NotificationOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDeliveryService
{
    public function __construct(
        private readonly TelegramNotificationRenderer $renderer,
        private readonly TelegramClient $telegram,
    ) {}

    public function deliverRecipient(string $channel, string $recipient): int
    {
        $delivered = 0;
        $limit = max(1, (int) config('notifications.outbox.delivery_batch_size', 25));

        for ($index = 0; $index < $limit; $index++) {
            $notification = $this->claimNext($channel, $recipient);

            if (! $notification instanceof NotificationOutbox) {
                break;
            }

            try {
                $message = $this->renderer->render($notification);
                $messageId = $this->telegram->send($message);
                $this->markSent($notification, $messageId);
                $delivered++;
            } catch (NotificationNotApplicableException $exception) {
                $this->markDiscarded($notification, $exception->getMessage());
            } catch (NotificationDeliveryException $exception) {
                $this->markDeliveryFailure($notification, $exception);
                break;
            } catch (Throwable $exception) {
                report($exception);
                $this->markDeliveryFailure(
                    $notification,
                    NotificationDeliveryException::transient('Unexpected notification delivery failure.', previous: $exception),
                );
                break;
            }
        }

        return $delivered;
    }

    private function claimNext(string $channel, string $recipient): ?NotificationOutbox
    {
        $staleBefore = now()->subMinutes(max(1, (int) config('notifications.outbox.processing_stale_minutes', 10)));

        return DB::transaction(function () use ($channel, $recipient, $staleBefore): ?NotificationOutbox {
            $retryIsWaiting = NotificationOutbox::query()
                ->where('channel', $channel)
                ->where('recipient', $recipient)
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->whereNotNull('last_error')
                ->where('available_at', '>', now())
                ->exists();

            if ($retryIsWaiting) {
                return null;
            }

            $notification = NotificationOutbox::query()
                ->where('channel', $channel)
                ->where('recipient', $recipient)
                ->where(function (Builder $query) use ($staleBefore): void {
                    $query->where(function (Builder $pending): void {
                        $pending->where('status', NotificationOutbox::STATUS_PENDING)
                            ->where('available_at', '<=', now());
                    })->orWhere(function (Builder $processing) use ($staleBefore): void {
                        $processing->where('status', NotificationOutbox::STATUS_PROCESSING)
                            ->where(function (Builder $stale) use ($staleBefore): void {
                                $stale->whereNull('processing_started_at')
                                    ->orWhere('processing_started_at', '<=', $staleBefore);
                            });
                    });
                })
                ->orderByRaw('CASE WHEN last_error IS NULL THEN 1 ELSE 0 END')
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $notification instanceof NotificationOutbox) {
                return null;
            }

            $notification->forceFill([
                'status' => NotificationOutbox::STATUS_PROCESSING,
                'attempts' => (int) $notification->attempts + 1,
                'processing_started_at' => now(),
                'failed_at' => null,
                'discarded_at' => null,
            ])->save();

            return $notification;
        }, 3);
    }

    private function markSent(NotificationOutbox $notification, string $messageId): void
    {
        NotificationOutbox::query()
            ->whereKey($notification->id)
            ->where('status', NotificationOutbox::STATUS_PROCESSING)
            ->update([
                'status' => NotificationOutbox::STATUS_SENT,
                'processing_started_at' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'discarded_at' => null,
                'provider_message_id' => $messageId !== '' ? $messageId : null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function markDiscarded(NotificationOutbox $notification, string $reason): void
    {
        NotificationOutbox::query()
            ->whereKey($notification->id)
            ->where('status', NotificationOutbox::STATUS_PROCESSING)
            ->update([
                'status' => NotificationOutbox::STATUS_DISCARDED,
                'processing_started_at' => null,
                'discarded_at' => now(),
                'last_error' => mb_substr($reason, 0, 2000),
                'updated_at' => now(),
            ]);
    }

    private function markDeliveryFailure(
        NotificationOutbox $notification,
        NotificationDeliveryException $exception,
    ): void {
        $attempts = (int) $notification->attempts;
        $maxAttempts = max(1, (int) config('notifications.outbox.max_attempts', 8));
        $willRetry = $exception->retryable && $attempts < $maxAttempts;
        $attributes = [
            'status' => $willRetry ? NotificationOutbox::STATUS_PENDING : NotificationOutbox::STATUS_FAILED,
            'processing_started_at' => null,
            'available_at' => $willRetry ? now()->addSeconds($this->backoffSeconds($attempts, $exception)) : $notification->available_at,
            'failed_at' => $willRetry ? null : now(),
            'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            'updated_at' => now(),
        ];

        NotificationOutbox::query()
            ->whereKey($notification->id)
            ->where('status', NotificationOutbox::STATUS_PROCESSING)
            ->update($attributes);

        Log::log($willRetry ? 'warning' : 'error', 'notifications.delivery_failed', [
            'notification_outbox_id' => $notification->id,
            'deduplication_key' => $notification->deduplication_key,
            'event_type' => $notification->event_type,
            'channel' => $notification->channel,
            'attempts' => $attempts,
            'will_retry' => $willRetry,
            'available_at' => $willRetry ? $attributes['available_at'] : null,
            'error' => $exception->getMessage(),
        ]);
    }

    private function backoffSeconds(int $attempts, NotificationDeliveryException $exception): int
    {
        if ($exception->retryAfterSeconds !== null) {
            return max(1, $exception->retryAfterSeconds);
        }

        $configured = config('notifications.outbox.backoff_seconds', [60, 300, 900, 3600, 21600]);
        $backoff = is_array($configured)
            ? array_values(array_filter(array_map('intval', $configured), fn (int $value): bool => $value > 0))
            : [];
        $backoff = $backoff === [] ? [60, 300, 900, 3600, 21600] : $backoff;

        return $backoff[min(max(0, $attempts - 1), count($backoff) - 1)];
    }
}
