<?php

namespace App\Console\Commands;

use App\Models\NotificationOutbox;
use Illuminate\Console\Command;

class RetryFailedNotifications extends Command
{
    protected $signature = 'notifications:retry-failed {--id=* : Failed outbox IDs to retry} {--all : Retry every failed notification}';

    protected $description = 'Reset selected terminal notification failures for another delivery cycle';

    public function handle(): int
    {
        $ids = array_values(array_filter(
            array_map('intval', (array) $this->option('id')),
            fn (int $id): bool => $id > 0,
        ));

        if (! $this->option('all') && $ids === []) {
            $this->error('Specify --id=<outbox-id> or --all.');

            return self::FAILURE;
        }

        $query = NotificationOutbox::query()->where('status', NotificationOutbox::STATUS_FAILED);

        if (! $this->option('all')) {
            $query->whereKey($ids);
        }

        $updated = $query->update([
            'status' => NotificationOutbox::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'processing_started_at' => null,
            'failed_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        $this->info("Failed notifications reset: {$updated}");

        return self::SUCCESS;
    }
}
