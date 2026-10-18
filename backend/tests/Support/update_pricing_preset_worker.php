<?php

declare(strict_types=1);

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Models\PricingPreset;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $presetId, $adminId, $name, $base, $expectedLinkedVehiclesCount, $barrier] = $argv;
$waitMicroseconds = max(0, (int) (((float) $barrier - microtime(true)) * 1_000_000));

if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

$prices = [];
$offset = 0;
foreach (PricingSeasonKey::cases() as $season) {
    foreach (PricingTier::cases() as $tier) {
        $prices[$season->value][$tier->value] = (int) $base + $offset++;
    }
}

try {
    $preset = PricingPreset::query()->findOrFail((int) $presetId);
    $updated = $app->make(PricingPresetService::class)->update($preset, [
        'name' => $name,
        'vehicle_type' => 'scooter',
        'version' => 1,
        'expected_linked_vehicles_count' => (int) $expectedLinkedVehiclesCount,
        'prices' => $prices,
    ], (int) $adminId, 'en');

    echo json_encode([
        'status' => 200,
        'operation' => 'update',
        'version' => $updated->version,
        'base' => (int) $base,
    ], JSON_THROW_ON_ERROR);
} catch (PricingPresetConflictException $exception) {
    echo json_encode([
        'status' => 409,
        'operation' => 'update',
        'error' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    echo json_encode(['status' => 500], JSON_THROW_ON_ERROR);
}
