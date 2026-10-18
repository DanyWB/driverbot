<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[
    $script,
    $operation,
    $presetId,
    $vehicleId,
    $presetVersion,
    $expectedPresetId,
    $expectedVehicleRevision,
    $barrier,
] = $argv;
$waitMicroseconds = max(0, (int) (((float) $barrier - microtime(true)) * 1_000_000));

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

try {
    $preset = PricingPreset::query()->findOrFail((int) $presetId);
    $vehicle = Vehicle::query()->findOrFail((int) $vehicleId);
    $service = $app->make(PricingPresetService::class);

    if ($operation === 'attach') {
        $updated = $service->attach(
            $vehicle,
            $preset,
            (int) $presetVersion,
            $expectedPresetId === 'null' ? null : (int) $expectedPresetId,
            (int) $expectedVehicleRevision,
            'en',
        );
    } elseif ($operation === 'detach') {
        $updated = $service->detach(
            $vehicle,
            (int) $presetVersion,
            (int) $expectedPresetId,
            (int) $expectedVehicleRevision,
            'en',
        );
    } else {
        throw new InvalidArgumentException("Unsupported operation: {$operation}");
    }

    echo json_encode([
        'status' => 200,
        'operation' => $operation,
        'pricing_preset_id' => $updated->pricing_preset_id,
        'pricing_version' => $updated->pricing_version,
    ], JSON_THROW_ON_ERROR);
} catch (PricingPresetConflictException $exception) {
    echo json_encode([
        'status' => 409,
        'operation' => $operation,
        'error' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    echo json_encode(['status' => 500, 'operation' => $operation], JSON_THROW_ON_ERROR);
}
