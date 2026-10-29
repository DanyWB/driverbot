<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Http\Controllers\PricingPresets\PricingToolsController;
use App\Models\PricingPreset;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
// Workers receive explicit test configuration through Process env/stdin only.
// Never read a developer/CI .env or allow a cached production configuration.
$app->useEnvironmentPath(__DIR__.'/no-environment-files');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Pricing race workers refuse cached application configuration.');
}
$app->make(Kernel::class)->bootstrap();
$operationLabel = 'unparsed';

try {
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('Pricing race worker requires a PostgreSQL testing environment.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $operationLabel = $input['label'];
    DB::statement("SET lock_timeout = '5s'");
    DB::statement("SET statement_timeout = '10s'");
    // Session advisory locks form a bounded readiness barrier: neither mutation
    // can start unless both real PostgreSQL worker connections are alive.
    DB::select('SELECT pg_advisory_lock(CAST(? AS integer), CAST(? AS integer))', [$input['barrier_key'], $input['slot']]);
    $readyDeadline = microtime(true) + 5;
    do {
        $ready = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = CAST(? AS oid) AND objid IN (1, 2) AND granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())", [$input['barrier_key']]);
        if ($ready === 2) {
            break;
        }
        if (microtime(true) >= $readyDeadline) {
            throw new RuntimeException('Both PostgreSQL race workers did not reach the readiness barrier.');
        }
        usleep(10_000);
    } while (true);
    $wait = max(0, (int) (((float) $input['barrier'] - microtime(true)) * 1_000_000));
    if ($wait > 0) {
        usleep($wait);
    }

    $status = 200;
    if ($input['operation'] === 'import') {
        $app->make(PricingSettingsImport::class)->commit($input['preview'], $input['targets'], $input['mapping'], $input['admin_id'], 'en');
    } elseif ($input['operation'] === 'attach') {
        $app->make(PricingPresetService::class)->attach(
            Vehicle::query()->findOrFail($input['vehicle_id']),
            PricingPreset::query()->findOrFail($input['preset_id']),
            $input['preset_version'],
            null,
            $input['vehicle_version'],
            'en',
        );
    } elseif ($input['operation'] === 'bulk') {
        $admin = User::query()->findOrFail($input['admin_id']);
        $request = Request::create('/pricing-presets/bulk', 'PATCH', $input['payload']);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $admin);
        $response = $app->call([$app->make(PricingToolsController::class), 'bulkUpdate'], ['request' => $request]);
        $status = $response->getStatusCode();
    } else {
        throw new InvalidArgumentException('Unknown pricing race operation.');
    }
    echo json_encode(['status' => $status, 'operation' => $input['label']], JSON_THROW_ON_ERROR);
} catch (PricingPresetConflictException $exception) {
    echo json_encode(['status' => 409, 'operation' => $operationLabel, 'error' => $exception->errorCode], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
