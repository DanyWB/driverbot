<?php

namespace App\Console\Commands;

use App\Jobs\FoundationQueueProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProbeQueue extends Command
{
    protected $signature = 'operations:queue-probe
                            {--verify= : Verify that a worker processed this probe ID}';

    protected $description = 'Dispatch or verify a harmless queue worker probe';

    public function handle(): int
    {
        $probeId = $this->option('verify');

        if (is_string($probeId) && $probeId !== '') {
            return $this->verify($probeId);
        }

        $probeId = (string) Str::uuid();
        Cache::forget(FoundationQueueProbe::cacheKey($probeId));
        FoundationQueueProbe::dispatch($probeId);

        $this->line($probeId);

        return self::SUCCESS;
    }

    private function verify(string $probeId): int
    {
        if (! Str::isUuid($probeId)) {
            $this->error('The probe ID must be a UUID.');

            return self::INVALID;
        }

        $processedAt = Cache::pull(FoundationQueueProbe::cacheKey($probeId));

        if (! is_string($processedAt)) {
            $this->error('Queue probe has not been processed.');

            return self::FAILURE;
        }

        $this->info("Queue probe processed at {$processedAt}.");

        return self::SUCCESS;
    }
}
