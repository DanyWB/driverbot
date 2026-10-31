<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebsitePage;
use App\Models\WebsiteSiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Uses committed synthetic fixtures, two distinct actors and independent PG sessions. */
class PostgresWebsiteCmsConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real PostgreSQL CMS concurrency requires the pgsql testing driver.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/_test(?:ing)?$/D', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(0, DB::transactionLevel(), 'Do not hide cross-session visibility behind a retained test transaction.');
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_first_page_creation_is_serialized_before_the_missing_row_select(): void
    {
        $this->race('page');
    }

    public function test_first_global_settings_singleton_creation_is_serialized_with_distinct_actors(): void
    {
        $this->race('settings');
    }

    private function race(string $kind): void
    {
        $this->assertFalse($kind === 'page' ? WebsitePage::query()->where('key', 'home')->exists() : WebsiteSiteSettings::query()->whereKey(1)->exists(), 'Only an isolated empty synthetic CMS key may be used.');
        $actors = User::factory()->count(2)->create();
        $gate = random_int(100000000, 2000000000);
        $label = 'cms-synthetic-'.bin2hex(random_bytes(6));
        DB::select('SELECT pg_advisory_lock(?)', [$gate]);
        $first = $this->worker($kind, (int) $actors[0]->id, $label.'-first', $gate);
        $second = $this->worker($kind, (int) $actors[1]->id, $label.'-second', null);
        $released = false;
        try {
            $first->start();
            $deadline = microtime(true) + 8;
            while (! str_contains($first->getOutput(), 'READY') && $first->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('READY', $first->getOutput(), $first->getErrorOutput());
            $second->start();
            $waiting = false;
            $deadline = microtime(true) + 8;
            while ($second->isRunning() && microtime(true) < $deadline) {
                $row = DB::selectOne('SELECT wait_event FROM pg_stat_activity WHERE application_name = ?', [$label.'-second']);
                if (($row->wait_event ?? null) === 'advisory') {
                    $waiting = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($waiting, 'Second distinct actor must wait on the natural-key advisory lock, not insert a duplicate.');
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $released = true;
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $lines = preg_split('/\R/', trim($first->getOutput()));
            $winner = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $loser = json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['status' => 'saved', 'revision' => 1], $winner);
            $this->assertSame(['status' => 'conflict', 'revision_error' => true], $loser);
            $this->assertSame(1, $kind === 'page' ? WebsitePage::query()->where('key', 'home')->count() : WebsiteSiteSettings::query()->whereKey(1)->count());
            $document = $kind === 'page' ? WebsitePage::query()->where('key', 'home')->firstOrFail() : WebsiteSiteSettings::query()->findOrFail(1);
            $this->assertSame(1, $document->revision);
            $this->assertNull($document->published_at);
        } finally {
            if (! $released) {
                DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            }
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            AuditLog::query()->whereIn('actor_admin_id', $actors->pluck('id')->all())->delete();
            if ($kind === 'page') {
                WebsitePage::query()->where('key', 'home')->delete();
            } else {
                WebsiteSiteSettings::query()->whereKey(1)->delete();
            }
            User::query()->whereKey($actors->pluck('id')->all())->delete();
        }
    }

    private function worker(string $kind, int $admin, string $label, ?int $gate): Process
    {
        $connection = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'DB_SEARCH_PATH' => (string) $connection['search_path'], 'DB_SSLMODE' => (string) $connection['sslmode'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'WEBSITE_BLOCK_OUTBOUND' => 'true'];
        $process = new Process([PHP_BINARY, base_path('tests/Support/website_document_race_worker.php')], base_path(), $environment, timeout: 25);
        $process->setInput(json_encode(['kind' => $kind, 'admin_id' => $admin, 'label' => $label, 'gate' => $gate], JSON_THROW_ON_ERROR));

        return $process;
    }
}
