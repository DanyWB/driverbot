<?php

declare(strict_types=1);
use App\Domain\Website\WebsitePreviewGuard;
use Illuminate\Contracts\Console\Kernel;

// Fresh preview database identity preflight. No marker/schema query or write.
try {
    $backend = realpath($argv[1] ?? '');
    if (PHP_SAPI !== 'cli' || $backend === false || ! str_starts_with($backend, '/srv/drive-phangan-preview/releases/')
        || is_link($argv[1] ?? '') || getenv('APP_ENV') !== 'production'
        || getenv('LARAVEL_STORAGE_PATH') !== '/srv/drive-phangan-preview/shared/storage'
        || realpath($backend.'/.env') !== '/srv/drive-phangan-preview/shared/private/backend.env'
        || is_file($backend.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Fresh runtime boundary or uncached configuration invalid.');
    }
    require $backend.'/vendor/autoload.php';
    $app = require $backend.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->basePath() !== $backend) {
        throw new RuntimeException('Unexpected cached/runtime application.');
    }
    $app->make(WebsitePreviewGuard::class)->assertDatabaseIdentity();
    echo "Fresh preview configuration/filesystem/actual PostgreSQL database-role identity verified before migrations.\n";
} catch (Throwable) {
    fwrite(STDERR, "Preview pre-migration runtime identity refused; no schema write is authorized.\n");
    exit(1);
}
