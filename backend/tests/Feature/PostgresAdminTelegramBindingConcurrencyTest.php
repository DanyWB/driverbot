<?php

namespace Tests\Feature;

use App\Domain\Administration\Services\AdminTelegramBindingService;
use App\Models\AdminTelegramBinding;
use App\Models\AdminTelegramBindingCode;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresAdminTelegramBindingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency test requires the pgsql driver.');
        }

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
    }

    public function test_one_binding_code_can_be_consumed_by_only_one_simultaneous_request(): void
    {
        $admin = User::factory()->create();

        try {
            $issued = app(AdminTelegramBindingService::class)->issueCode($admin);
            $firstTelegramId = $this->telegramId(maximum: 9_999_999_998);
            $secondTelegramId = (string) ((int) $firstTelegramId + 1);
            $barrier = (string) (microtime(true) + 1.0);
            $first = $this->worker($issued->code, $firstTelegramId, $barrier);
            $second = $this->worker($issued->code, $secondTelegramId, $barrier);

            $results = $this->runWorkers($first, $second);
            usort($results, fn (array $left, array $right): int => $left['status'] <=> $right['status']);

            $this->assertSame(200, $results[0]['status']);
            $this->assertSame(422, $results[1]['status']);
            $this->assertSame('telegram_binding_code_invalid', $results[1]['error_code']);
            $this->assertSame(1, AdminTelegramBinding::query()->where('admin_user_id', $admin->id)->count());

            $code = AdminTelegramBindingCode::query()
                ->where('admin_user_id', $admin->id)
                ->sole();
            $this->assertNotNull($code->consumed_at);
            $this->assertNull($code->revoked_at);
        } finally {
            $this->cleanup(collect([$admin]));
        }
    }

    public function test_one_telegram_identity_can_be_claimed_by_only_one_administrator_simultaneously(): void
    {
        $admins = User::factory()->count(2)->create();

        try {
            $service = app(AdminTelegramBindingService::class);
            $firstCode = $service->issueCode($admins[0]);
            $secondCode = $service->issueCode($admins[1]);
            $telegramId = $this->telegramId();
            $barrier = (string) (microtime(true) + 1.0);
            $first = $this->worker($firstCode->code, $telegramId, $barrier);
            $second = $this->worker($secondCode->code, $telegramId, $barrier);

            $results = $this->runWorkers($first, $second);
            usort($results, fn (array $left, array $right): int => $left['status'] <=> $right['status']);

            $this->assertSame(200, $results[0]['status']);
            $this->assertSame(409, $results[1]['status']);
            $this->assertSame('telegram_account_already_bound', $results[1]['error_code']);

            $binding = AdminTelegramBinding::query()
                ->whereIn('admin_user_id', $admins->pluck('id')->all())
                ->sole();
            $this->assertSame($telegramId, $binding->telegram_user_id);
            $this->assertSame($telegramId, $binding->telegram_chat_id);
            $this->assertContains($binding->admin_user_id, $admins->pluck('id')->all());

            $codes = AdminTelegramBindingCode::query()
                ->whereIn('admin_user_id', $admins->pluck('id')->all())
                ->get();
            $this->assertSame(1, $codes->whereNotNull('consumed_at')->count());
            $this->assertSame(1, $codes->whereNull('consumed_at')->count());
        } finally {
            $this->cleanup($admins);
        }
    }

    /**
     * @return list<array{status: int, error_code: string|null}>
     */
    private function runWorkers(Process $first, Process $second): array
    {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $errors = $first->getErrorOutput().$second->getErrorOutput();

        $this->assertTrue($first->isSuccessful(), $errors);
        $this->assertTrue($second->isSuccessful(), $errors);

        return [
            $this->workerResult($first),
            $this->workerResult($second),
        ];
    }

    /** @return array{status: int, error_code: string|null} */
    private function workerResult(Process $process): array
    {
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($result) || ! is_int($result['status'] ?? null)) {
            $this->fail('Telegram binding worker returned an invalid response: '.$process->getOutput());
        }

        $errorCode = $result['error_code'] ?? null;

        return [
            'status' => $result['status'],
            'error_code' => is_string($errorCode) ? $errorCode : null,
        ];
    }

    private function worker(string $code, string $telegramId, string $barrier): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
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
            base_path('tests/Support/connect_admin_telegram_worker.php'),
            $code,
            $telegramId,
            $barrier,
        ], base_path(), $environment, timeout: 20);
    }

    private function telegramId(int $maximum = 9_999_999_999): string
    {
        return (string) random_int(1_000_000_000, $maximum);
    }

    /** @param Collection<int, User> $admins */
    private function cleanup(Collection $admins): void
    {
        $adminIds = $admins->pluck('id')->all();

        AdminTelegramBindingCode::query()->whereIn('admin_user_id', $adminIds)->delete();
        AdminTelegramBinding::query()->whereIn('admin_user_id', $adminIds)->delete();
        User::query()->whereKey($adminIds)->delete();
    }
}
