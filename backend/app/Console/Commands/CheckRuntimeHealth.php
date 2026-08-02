<?php

namespace App\Console\Commands;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Jobs\FoundationQueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class CheckRuntimeHealth extends Command
{
    protected $signature = 'operations:runtime-status
                            {--wait=15 : Seconds to wait for the queue probe}
                            {--skip-queue : Do not probe the default queue worker}
                            {--skip-scheduler : Do not require a fresh scheduler heartbeat}
                            {--json : Render machine-readable JSON}';

    protected $description = 'Check database, Redis, scheduler and default queue worker health';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $checks = [
            $this->dependency('database', fn () => DB::select('select 1')),
            $this->dependency('redis', fn () => Redis::connection()->ping()),
        ];

        if (! $this->option('skip-scheduler')) {
            $lastSeenAt = $heartbeat->lastSeenAt();
            $checks[] = $heartbeat->isFresh()
                ? $this->passedCheck('scheduler', 'Last heartbeat: '.$lastSeenAt?->toIso8601String().'.')
                : $this->failedCheck('scheduler', 'Scheduler heartbeat is missing or stale.');
        }

        if (! $this->option('skip-queue')) {
            $checks[] = $this->queueProbe();
        }

        $passed = collect($checks)->every(
            fn (ReleaseCheck $check): bool => $check->status === ReleaseCheck::PASS,
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $passed,
                'checks' => array_map(
                    fn (ReleaseCheck $check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $this->table(['Check', 'Status', 'Details'], array_map(
                fn (ReleaseCheck $check): array => [$check->id, strtoupper($check->status), $check->message],
                $checks,
            ));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function dependency(string $id, callable $probe): ReleaseCheck
    {
        try {
            $probe();

            return $this->passedCheck($id, ucfirst($id).' is available.');
        } catch (Throwable $exception) {
            return $this->failedCheck($id, ucfirst($id).' check failed: '.$exception->getMessage());
        }
    }

    private function queueProbe(): ReleaseCheck
    {
        $probeId = (string) Str::uuid();
        $key = FoundationQueueProbe::cacheKey($probeId);
        Cache::forget($key);
        FoundationQueueProbe::dispatch($probeId);
        $deadline = microtime(true) + min(60, max(1, (int) $this->option('wait')));

        do {
            $processedAt = Cache::get($key);

            if (is_string($processedAt)) {
                Cache::forget($key);

                return $this->passedCheck('queue.default', "Queue probe processed at {$processedAt}.");
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return $this->failedCheck('queue.default', 'Default queue worker did not process the probe in time.');
    }

    private function passedCheck(string $id, string $message): ReleaseCheck
    {
        return new ReleaseCheck($id, ReleaseCheck::PASS, $message);
    }

    private function failedCheck(string $id, string $message): ReleaseCheck
    {
        return new ReleaseCheck($id, ReleaseCheck::FAILURE, $message);
    }
}
