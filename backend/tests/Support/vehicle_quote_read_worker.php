<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__.'/no-environment-files');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Vehicle quote read workers refuse cached application configuration.');
}
$app->make(Kernel::class)->bootstrap();

try {
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('Vehicle quote read worker requires a PostgreSQL testing environment.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    DB::statement("SET lock_timeout = '10s'");
    DB::statement("SET statement_timeout = '15s'");
    DB::select("SELECT set_config('application_name', ?, false)", [$input['label']]);
    $vehicle = Vehicle::query()->whereKey($input['vehicle_id'])->firstOrFail();
    $period = RentalPeriod::fromStrings('2027-01-02', '2027-01-10');
    $gate = function () use ($input): void {
        DB::select('SELECT pg_advisory_lock(?)', [$input['gate']]);
        DB::select('SELECT pg_advisory_unlock(?)', [$input['gate']]);
    };
    if ($input['operation'] === 'read') {
        $paused = false;
        DB::listen(function (QueryExecuted $query) use ($gate, &$paused): void {
            // The policy lookup occurs after parent-lock acquisition, before
            // calculator/legacy mode selection and the real price query.
            if (! $paused && str_contains($query->sql, 'from "rental_policies"')) {
                $paused = true;
                $gate();
            }
        });
        $result = $app->make(PricingService::class)->quote($vehicle, $period)->toArray();
        if (! $paused) {
            throw new RuntimeException('Vehicle quote did not reach its pre-price barrier.');
        }
    } elseif ($input['operation'] === 'outer-read') {
        $result = DB::transaction(function () use ($app, $vehicle, $period, $gate): array {
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $quote = $app->make(PricingService::class)->quote($locked, $period);
            $gate();

            return $quote->toArray();
        });
    } elseif ($input['operation'] === 'plain-read') {
        $result = $app->make(PricingService::class)->quote($vehicle, $period)->toArray();
    } elseif ($input['operation'] === 'preset-update') {
        $preset = PricingPreset::query()->whereKey($input['preset_id'])->firstOrFail();
        $saved = $app->make(PricingPresetService::class)->update($preset, [...$input['data'], 'version' => 1, 'expected_linked_vehicles_count' => 1], (int) $input['admin_id'], 'en');
        $result = ['version' => (int) $saved->version];
    } elseif ($input['operation'] === 'manual-update') {
        $saved = $app->make(VehiclePricingService::class)->replaceMatrix($vehicle, $input['prices'], $input['enabled'], 3);
        $result = ['revision' => (int) $saved->pricing_version];
    } else {
        throw new InvalidArgumentException('Unknown vehicle quote read operation.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
