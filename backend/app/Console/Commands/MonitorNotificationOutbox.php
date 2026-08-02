<?php

namespace App\Console\Commands;

use App\Models\NotificationOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorNotificationOutbox extends Command
{
    protected $signature = 'notifications:monitor';

    protected $description = 'Report failed, overdue and stale notification outbox records';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) config('notifications.outbox.processing_stale_minutes', 10));
        $overdueMinutes = max(1, (int) config('notifications.outbox.monitor_overdue_minutes', 15));
        $metrics = [
            'failed' => NotificationOutbox::query()->where('status', NotificationOutbox::STATUS_FAILED)->count(),
            'overdue_pending' => NotificationOutbox::query()
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->where('available_at', '<=', now()->subMinutes($overdueMinutes))
                ->count(),
            'stale_processing' => NotificationOutbox::query()
                ->where('status', NotificationOutbox::STATUS_PROCESSING)
                ->where(function ($query) use ($staleMinutes): void {
                    $query->whereNull('processing_started_at')
                        ->orWhere('processing_started_at', '<=', now()->subMinutes($staleMinutes));
                })
                ->count(),
        ];
        $configurationIssues = array_values(array_filter([
            trim((string) config('notifications.telegram.bot_token')) === '' ? 'TELEGRAM_BOT_TOKEN is missing' : null,
            trim((string) config('notifications.telegram.admin_chat_id')) === '' ? 'TELEGRAM_ADMIN_CHAT_ID is missing' : null,
        ]));
        $healthy = array_sum($metrics) === 0 && $configurationIssues === [];

        $this->table(['Metric', 'Value'], collect($metrics)->map(
            fn (int $value, string $name): array => [$name, $value],
        )->values()->all());

        foreach ($configurationIssues as $issue) {
            $this->warn($issue);
        }

        if (! $healthy) {
            Log::warning('notifications.outbox_unhealthy', [
                ...$metrics,
                'configuration_issues' => $configurationIssues,
            ]);

            return self::FAILURE;
        }

        $this->info('Notification outbox is healthy.');

        return self::SUCCESS;
    }
}
