<?php

namespace App\Console\Commands;

use App\Domain\Operations\Services\SchedulerHeartbeat;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'operations:scheduler-heartbeat';

    protected $description = 'Record a scheduler heartbeat in the shared cache';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $timestamp = $heartbeat->touch();
        $this->info('Scheduler heartbeat recorded at '.$timestamp->toIso8601String().'.');

        return self::SUCCESS;
    }
}
