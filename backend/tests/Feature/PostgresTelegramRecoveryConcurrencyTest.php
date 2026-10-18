<?php

namespace Tests\Feature;

use App\Domain\Administration\Services\TelegramPasswordRecovery;
use App\Models\AdminTelegramBinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresTelegramRecoveryConcurrencyTest extends TestCase
{
    public function test_same_reset_link_cannot_change_password_twice_concurrently(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL concurrency test requires pgsql.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'notifications.telegram.bot_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $admin = User::factory()->create();
        $telegramId = (string) random_int(1_000_000_000, 9_999_999_999);
        try {
            AdminTelegramBinding::query()->create([
                'admin_user_id' => $admin->id, 'telegram_user_id' => $telegramId, 'telegram_chat_id' => $telegramId,
                'generation' => 1, 'locale' => 'en', 'connected_at' => now(),
            ]);
            app(TelegramPasswordRecovery::class)->deliver($admin->email, now()->getTimestamp());
            $record = Http::recorded()->last();
            $this->assertNotNull($record);
            $url = $record[0]['reply_markup']['inline_keyboard'][0][0]['url'];
            $token = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
            $input = ['email' => $admin->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
            $barrier = (string) (microtime(true) + 1.0);
            $first = $this->worker($input, $barrier);
            $second = $this->worker($input, $barrier);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $statuses = [trim($first->getOutput()), trim($second->getOutput())];
            sort($statuses);
            $expected = [Password::PASSWORD_RESET, Password::INVALID_TOKEN];
            sort($expected);
            $this->assertSame($expected, $statuses);
            $this->assertSame(1, $admin->fresh()->session_version);
            $this->assertFalse(DB::table('password_reset_tokens')->where('email', $admin->email)->exists());
        } finally {
            DB::table('audit_logs')->where('subject_type', User::class)->where('subject_id', $admin->id)->delete();
            DB::table('password_reset_tokens')->where('email', $admin->email)->delete();
            AdminTelegramBinding::query()->where('admin_user_id', $admin->id)->delete();
            $admin->delete();
        }
    }

    /** @param array<string, string> $input */
    private function worker(array $input, string $barrier): Process
    {
        $connection = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'DB_SEARCH_PATH' => (string) $connection['search_path'],
            'DB_SSLMODE' => (string) $connection['sslmode'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ];

        // Recovery secrets are passed through stdin, never process arguments or output.
        return new Process([PHP_BINARY, base_path('tests/Support/reset_telegram_password_worker.php'), $barrier],
            base_path(), $env, json_encode($input, JSON_THROW_ON_ERROR), 20);
    }
}
