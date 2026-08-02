<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Services\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(SchedulerHeartbeat $heartbeat): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => config('health.redis') ? $this->checkRedis() : 'skipped',
            'scheduler' => config('health.scheduler') ? $this->checkScheduler($heartbeat) : 'skipped',
        ];
        $ready = ! in_array('failed', $checks, true);

        return response()
            ->json([
                'status' => $ready ? 'ready' : 'unavailable',
                'checks' => $checks,
            ], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private function checkRedis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private function checkScheduler(SchedulerHeartbeat $heartbeat): string
    {
        return $heartbeat->isFresh() ? 'ok' : 'failed';
    }
}
