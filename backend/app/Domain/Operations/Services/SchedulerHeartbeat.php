<?php

namespace App\Domain\Operations\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeat
{
    public function touch(): CarbonImmutable
    {
        $timestamp = CarbonImmutable::now();
        Cache::put($this->cacheKey(), $timestamp->toIso8601String(), now()->addSeconds($this->ttlSeconds()));

        return $timestamp;
    }

    public function lastSeenAt(): ?CarbonImmutable
    {
        $value = Cache::get($this->cacheKey());

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isFresh(): bool
    {
        $lastSeenAt = $this->lastSeenAt();

        return $lastSeenAt instanceof CarbonImmutable
            && $lastSeenAt->greaterThanOrEqualTo(now()->subSeconds($this->maxAgeSeconds()));
    }

    public function cacheKey(): string
    {
        return (string) config('operations.scheduler_heartbeat.cache_key', 'operations:scheduler-heartbeat');
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('operations.scheduler_heartbeat.ttl_seconds', 600));
    }

    private function maxAgeSeconds(): int
    {
        return max(60, (int) config('operations.scheduler_heartbeat.max_age_seconds', 180));
    }
}
