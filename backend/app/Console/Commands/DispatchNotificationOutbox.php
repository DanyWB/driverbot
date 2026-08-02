<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotificationRecipient;
use App\Models\NotificationOutbox;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class DispatchNotificationOutbox extends Command
{
    protected $signature = 'notifications:dispatch-outbox {--limit= : Maximum recipient jobs to dispatch}';

    protected $description = 'Dispatch due notification outbox recipients to the queue';

    public function handle(): int
    {
        $configuredLimit = max(1, (int) config('notifications.outbox.recipient_batch_size', 50));
        $limit = $this->option('limit') === null
            ? $configuredLimit
            : min(1000, max(1, (int) $this->option('limit')));
        $staleBefore = now()->subMinutes(max(1, (int) config('notifications.outbox.processing_stale_minutes', 10)));
        $recipients = NotificationOutbox::query()
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
            ->orderBy('channel')
            ->orderBy('recipient')
            ->distinct()
            ->limit($limit)
            ->get(['channel', 'recipient']);

        foreach ($recipients as $recipient) {
            DeliverNotificationRecipient::dispatch(
                (string) $recipient->channel,
                (string) $recipient->recipient,
            );
        }

        $this->info("Notification recipient jobs dispatched: {$recipients->count()}");

        return self::SUCCESS;
    }
}
