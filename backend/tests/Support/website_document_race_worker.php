<?php

declare(strict_types=1);

use App\Domain\Website\Content\WebsiteDocumentService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__.'/no-environment-files');
if ($app->configurationIsCached()) {
    throw new RuntimeException('CMS test worker refuses config cache.');
}
$app->make(Kernel::class)->bootstrap();
try {
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('Testing PostgreSQL required.');
    }
    $database = DB::selectOne('SELECT current_database() AS name')->name;
    if (! preg_match('/_test(?:ing)?$/D', $database)) {
        throw new RuntimeException('Dedicated testing database required.');
    }
    Http::preventStrayRequests();
    Queue::fake();
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    DB::statement("SET lock_timeout = '5s'");
    DB::statement("SET statement_timeout = '15s'");
    DB::select("SELECT set_config('application_name', ?, false)", [$input['label']]);
    $held = false;
    if ($input['gate'] !== null) {
        $table = $input['kind'] === 'page' ? 'website_pages' : 'website_site_settings';
        DB::listen(function (QueryExecuted $event) use ($input, $table, &$held): void {
            if (! $held && str_contains($event->sql, '"'.$table.'"') && str_contains($event->sql, 'for update')) {
                $held = true;
                // Real absent-row SELECT completed; the service must already
                // hold its natural-key advisory lock before this gate.
                echo "READY\n";
                flush();
                DB::select('SELECT pg_advisory_lock(?)', [$input['gate']]);
                DB::select('SELECT pg_advisory_unlock(?)', [$input['gate']]);
            }
        });
    }
    $actor = User::query()->findOrFail($input['admin_id']);
    $service = app(WebsiteDocumentService::class);
    $document = $input['kind'] === 'page' ? $service->savePageDraft($actor, 'home', ['title' => ['en' => 'SYNTHETIC PG FIRST']], 0)
        : $service->saveSettingsDraft($actor, ['business' => ['name' => 'SYNTHETIC PG FIRST']], 0);
    echo json_encode(['status' => 'saved', 'revision' => $document->revision], JSON_THROW_ON_ERROR),"\n";
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'conflict', 'revision_error' => isset($exception->errors()['revision'])], JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'CMS synthetic worker stopped: '.$exception::class."\n");
    exit(1);
}
