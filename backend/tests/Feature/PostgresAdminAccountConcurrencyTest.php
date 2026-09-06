<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresAdminAccountConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency test requires the pgsql driver.');
        }
    }

    public function test_two_simultaneous_self_deletions_cannot_remove_every_active_administrator(): void
    {
        if (User::query()->where('is_active', true)->exists()) {
            $this->markTestSkipped('Administrator concurrency test requires an isolated database without existing administrators.');
        }

        $users = User::factory()->count(2)->create();

        try {
            $barrier = (string) (microtime(true) + 1.0);
            $first = $this->worker((int) $users[0]->id, $barrier);
            $second = $this->worker((int) $users[1]->id, $barrier);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            $results = [
                json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR)['result'],
                json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR)['result'],
            ];
            sort($results);

            $this->assertSame(['blocked', 'deleted'], $results, $first->getErrorOutput().$second->getErrorOutput());
            $this->assertSame(1, User::query()->where('is_active', true)->count());
        } finally {
            User::query()->whereKey($users->pluck('id')->all())->delete();
        }
    }

    private function worker(int $userId, string $barrier): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_SEARCH_PATH' => (string) $connection['search_path'],
            'DB_SSLMODE' => (string) $connection['sslmode'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        return new Process([
            PHP_BINARY,
            base_path('tests/Support/delete_admin_worker.php'),
            (string) $userId,
            $barrier,
        ], base_path(), $environment, timeout: 20);
    }
}
