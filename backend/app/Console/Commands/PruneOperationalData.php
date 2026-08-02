<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use App\Models\NotificationOutbox;
use Illuminate\Console\Command;

class PruneOperationalData extends Command
{
    protected $signature = 'operations:housekeeping {--dry-run : Count records without deleting them}';

    protected $description = 'Prune expired idempotency keys and delivered notification history';

    public function handle(): int
    {
        $sentBefore = now()->subDays(max(1, (int) config('notifications.outbox.sent_retention_days', 90)));
        $discardedBefore = now()->subDays(max(1, (int) config('notifications.outbox.discarded_retention_days', 30)));
        $queries = [
            'idempotency_keys' => IdempotencyKey::query()->where('expires_at', '<=', now()),
            'sent_notifications' => NotificationOutbox::query()
                ->where('status', NotificationOutbox::STATUS_SENT)
                ->where('sent_at', '<=', $sentBefore),
            'discarded_notifications' => NotificationOutbox::query()
                ->where('status', NotificationOutbox::STATUS_DISCARDED)
                ->where('discarded_at', '<=', $discardedBefore),
        ];
        $rows = [];

        foreach ($queries as $name => $query) {
            $count = (clone $query)->count();

            if (! $this->option('dry-run')) {
                $query->delete();
            }

            $rows[] = [$name, $count];
        }

        $this->table(['Data set', $this->option('dry-run') ? 'Would prune' : 'Pruned'], $rows);

        return self::SUCCESS;
    }
}
