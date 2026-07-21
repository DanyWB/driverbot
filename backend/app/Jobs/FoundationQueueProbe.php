<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FoundationQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $probeId) {}

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->probeId), now()->toIso8601String(), now()->addMinutes(5));

        Log::info('Foundation queue probe processed', [
            'probe_id' => $this->probeId,
        ]);
    }

    public static function cacheKey(string $probeId): string
    {
        return "operations:queue-probe:{$probeId}";
    }
}
