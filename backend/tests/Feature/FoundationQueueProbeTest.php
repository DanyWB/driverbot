<?php

namespace Tests\Feature;

use App\Jobs\FoundationQueueProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationQueueProbeTest extends TestCase
{
    public function test_probe_command_dispatches_a_queue_job(): void
    {
        Queue::fake();

        $this->artisan('operations:queue-probe')->assertSuccessful();

        Queue::assertPushed(
            FoundationQueueProbe::class,
            fn (FoundationQueueProbe $job): bool => Str::isUuid($job->probeId),
        );
    }

    public function test_processed_probe_can_be_verified_once(): void
    {
        $probeId = (string) Str::uuid();
        (new FoundationQueueProbe($probeId))->handle();

        $this->artisan('operations:queue-probe', ['--verify' => $probeId])
            ->expectsOutputToContain('Queue probe processed at')
            ->assertSuccessful();

        $this->assertFalse(Cache::has(FoundationQueueProbe::cacheKey($probeId)));
    }

    public function test_invalid_probe_id_is_rejected(): void
    {
        $this->artisan('operations:queue-probe', ['--verify' => 'invalid'])
            ->expectsOutput('The probe ID must be a UUID.')
            ->assertExitCode(2);
    }
}
