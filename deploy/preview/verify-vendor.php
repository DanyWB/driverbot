<?php

declare(strict_types=1);
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Pricing\Services\PricingSettings;
use App\Domain\Website\WebsitePreviewGuard;
use App\Domain\Website\WebsitePreviewImporter;
use App\Http\Controllers\Website\WebsiteController;

try {
    $backend = realpath($argv[1] ?? '');
    $expectedHash = $argv[2] ?? '';
    if ($backend === false || ! str_starts_with($backend, '/srv/drive-phangan-preview/releases/')
        || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || hash_file('sha256', $backend.'/composer.lock') !== $expectedHash
        || is_link($backend.'/vendor') || realpath($backend.'/vendor') !== $backend.'/vendor') {
        throw new RuntimeException('Preview vendor root or lock mismatch.');
    }
    foreach (['pdo_pgsql', 'gd', 'intl', 'mbstring', 'openssl', 'curl', 'dom', 'fileinfo'] as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException('Preview runtime extension missing.');
        }
    }
    require $backend.'/vendor/composer/platform_check.php';
    $loader = require $backend.'/vendor/autoload.php';
    foreach ([WebsitePreviewGuard::class, WebsitePreviewImporter::class,
        WebsiteController::class, BookingService::class,
        PricingSettings::class] as $class) {
        $path = (new ReflectionClass($class))->getFileName();
        if (! is_string($path) || ! str_starts_with(realpath($path), $backend.'/app/')) {
            throw new RuntimeException('App autoload resolved outside the preview release.');
        }
    }
    foreach ($loader->getClassMap() as $path) {
        $resolved = realpath($path);
        if ($resolved === false || ! str_starts_with($resolved, $backend.'/')) {
            throw new RuntimeException('Classmap resolves outside independent preview code/vendor.');
        }
    }
    echo "Independent vendor/platform/new-and-baseline App Reflection verification passed.\n";
} catch (Throwable) {
    fwrite(STDERR, "Preview vendor verification refused.\n");
    exit(1);
}
