<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PricingPresetService;
use App\Http\Controllers\PricingPresets\PricingToolsController;
use App\Models\PricingPreset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__.'/no-environment-files');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Pricing read workers refuse cached application configuration.');
}
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('Pricing read worker requires a PostgreSQL testing environment.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '15s'");
    DB::select("SELECT set_config('application_name', ?, false)", [$input['label']]);
    $preset = PricingPreset::query()->whereKey($input['preset_id'])->firstOrFail();
    if ($input['operation'] === 'read') {
        // Pause immediately before the real child-price query. The controller
        // must already hold the parent shared lock at this exact boundary.
        $app->instance(PricingPresetService::class, new class((int) $input['gate']) extends PricingPresetService
        {
            public function __construct(private readonly int $gate) {}

            public function matrix(PricingPreset $preset): array
            {
                DB::select('SELECT pg_advisory_lock(?)', [$this->gate]);
                DB::select('SELECT pg_advisory_unlock(?)', [$this->gate]);

                return parent::matrix($preset);
            }
        });
        $request = Request::create('/pricing-presets/'.$preset->id.'/calculate', 'GET', ['days' => 8, 'season' => 'high']);
        $response = $app->call([$app->make(PricingToolsController::class), 'calculate'], ['request' => $request, 'pricingPreset' => $preset]);
        echo $response->getContent();
    } elseif ($input['operation'] === 'write') {
        $saved = $app->make(PricingPresetService::class)->update($preset, [...$input['data'], 'version' => 1, 'expected_linked_vehicles_count' => 0], (int) $input['admin_id'], 'en');
        echo json_encode(['version' => (int) $saved->version], JSON_THROW_ON_ERROR);
    } else {
        throw new InvalidArgumentException('Unknown pricing read operation.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
