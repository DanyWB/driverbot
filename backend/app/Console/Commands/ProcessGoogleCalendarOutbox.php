<?php

namespace App\Console\Commands;

use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxWorker;
use Illuminate\Console\Command;

class ProcessGoogleCalendarOutbox extends Command
{
    protected $signature = 'google-calendar:process-outbox {--limit= : Maximum operations to process in this run}';

    protected $description = 'Claim and process due Google Calendar outbox operations';

    public function handle(GoogleCalendarOutboxWorker $worker): int
    {
        if (app()->isProduction() && config('google_calendar.driver') !== 'google') {
            $this->error('The local Google Calendar processor is disabled in production; set GOOGLE_CALENDAR_DRIVER=google.');

            return self::FAILURE;
        }

        $option = $this->option('limit');
        $limit = $option === null ? null : min(1000, max(1, (int) $option));
        $counts = $worker->processBatch($limit);

        $this->info("Google Calendar outbox: {$counts['claimed']} claimed, {$counts['processed']} acknowledged, {$counts['failed']} failed or lease-lost.");

        return self::SUCCESS;
    }
}
