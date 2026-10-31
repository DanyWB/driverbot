<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Production-only bounded entry point. Review/GO and a verified fresh backup are
// operational prerequisites, not inferred from the old LOCAL mapping approval.
ini_set('display_errors', '0');

try {
    $mode = $argv[1] ?? '';
    $backend = $argv[2] ?? '';
    $inputs = $argv[3] ?? '';
    if (PHP_SAPI !== 'cli' || ! in_array($mode, ['fingerprint', 'inspect', 'apply'], true)
        || ! preg_match('~^/srv/drive-phangan/releases/[0-9]{8}T[0-9]{6}Z/backend$~D', $backend)
        || realpath($backend) !== $backend || is_link($backend)
        || ! preg_match('~^/srv/drive-phangan/price-release-20261031-operation-[0-9]{8}T[0-9]{6}Z/inputs$~D', $inputs)
        || realpath($inputs) !== $inputs || is_link($inputs)) {
        throw new RuntimeException('Unexpected isolated operation paths/mode.');
    }
    require $backend.'/vendor/autoload.php';
    require __DIR__.'/ReviewedPriceImport.php';
    require __DIR__.'/PriceReleaseEvidence.php';
    $read = static function (string $name, string $sha) use ($inputs): array {
        $file = $inputs.'/'.$name;
        if (realpath($file) !== $file || is_link($file) || ! is_file($file) || filesize($file) > 2_000_000) {
            throw new RuntimeException('Source input provenance mismatch.');
        }

        $json = file_get_contents($file);
        if ($json === false || strlen($json) > 2_000_000 || ! hash_equals($sha, hash('sha256', $json))) {
            throw new RuntimeException('Source input unreadable.');
        }

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    };
    $payload = $read('pricing-source.json', ReviewedPriceImport::SOURCE_SHA);
    $mapping = ReviewedPriceImport::mapping($read('mapping.json', ReviewedPriceImport::MAPPING_SHA));
    $reference = $read('catalog-snapshot.json', ReviewedPriceImport::SNAPSHOT_SHA);
    $app = require $backend.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection();
    $identity = $db->selectOne('SELECT current_database() AS database, current_user AS role');
    if (! $app->environment('production') || $app->basePath() !== $backend || $db->getDriverName() !== 'pgsql'
        || $identity->database !== 'drive_phangan' || $identity->role !== 'drive_phangan_app'
        || realpath(storage_path()) !== '/srv/drive-phangan/shared/backend/storage') {
        throw new RuntimeException('Actual production runtime identity mismatch.');
    }
    $runner = new ReviewedPriceImport;
    if ($mode !== 'apply') {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->statement("SET LOCAL statement_timeout = '10000ms'");
            $result = $mode === 'fingerprint' ? $runner->fingerprint() : $runner->inspect($payload, $mapping, $reference);
        } finally {
            $db->rollBack();
        }
    } else {
        $operation = dirname($inputs);
        $baselineFile = $operation.'/baseline.json';
        $receiptFile = $operation.'/verified-backup.json';
        foreach ([$baselineFile, $receiptFile] as $file) {
            if (realpath($file) !== $file || is_link($file) || ! is_file($file) || (fileperms($file) & 0077) !== 0 || filesize($file) > 100_000) {
                throw new RuntimeException('Protected fresh backup evidence missing.');
            }
        }
        $receiptJson = file_get_contents($receiptFile);
        if ($receiptJson === false) {
            throw new RuntimeException('Backup receipt unreadable.');
        }
        $baselineJson = file_get_contents($baselineFile);
        if ($baselineJson === false) {
            throw new RuntimeException('Backup baseline unreadable.');
        }
        $adminId = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT);
        if ($adminId === false || $adminId < 1 || getenv('FINAL_PRICES_EXECUTION_GO') !== ReviewedPriceImport::SOURCE_SHA
            || ! is_file(storage_path('framework/down'))) {
            throw new RuntimeException('Exact execution GO, verified backup or maintenance boundary missing.');
        }
        if (! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new RuntimeException('Run as existing production runtime user, never root.');
        }
        $proof = getenv('FINAL_PRICES_BACKUP_VERIFIED');
        if ($proof === false) {
            throw new RuntimeException('Explicit backup receipt proof missing.');
        }
        $receipt = PriceReleaseEvidence::receipt($receiptJson, $baselineJson, $proof);
        PriceReleaseEvidence::backup($receipt['path'], $receipt['sha256sums_sha256'], posix_geteuid());
        foreach (['drive-phangan-bot', 'drive-phangan-worker-default', 'drive-phangan-worker-notifications', 'drive-phangan-scheduler'] as $unit) {
            $process = proc_open(['systemctl', 'is-active', $unit], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot verify stopped writers.');
            }
            $status = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if ($status !== 'inactive') {
                throw new RuntimeException('Application writer is not inactive.');
            }
        }
        $baseline = json_decode($baselineJson, true, 32, JSON_THROW_ON_ERROR);
        $result = $runner->apply($payload, $mapping, $reference, $adminId, $baseline);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Reviewed production pricing operation refused. No automatic retry, restore, service action or code rollback is authorized.\n");
    exit(1);
}
