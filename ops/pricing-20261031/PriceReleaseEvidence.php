<?php

declare(strict_types=1);

final class PriceReleaseEvidence
{
    /** @return array{path:string,sha256sums_sha256:string} */
    public static function receipt(string $json, string $baseline, string $expectedReceiptSha): array
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($value) || hash('sha256', $json) !== $expectedReceiptSha
            || ($value['format'] ?? null) !== 'PREPRICE-VERIFIED-BACKUP-1'
            || ($value['baseline_sha256'] ?? null) !== hash('sha256', $baseline)
            || ($value['source_revision'] ?? null) !== '6792e28929cfe20b1cf3c9678929e27bfaf61e80'
            || ! is_string($value['path'] ?? null) || ! preg_match('~^/srv/drive-phangan/backups/[0-9]{8}T[0-9]{6}Z$~D', $value['path'])
            || ! is_string($value['sha256sums_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $value['sha256sums_sha256'])) {
            throw new RuntimeException('Exact verified backup receipt/baseline identity required.');
        }

        return ['path' => $value['path'], 'sha256sums_sha256' => $value['sha256sums_sha256']];
    }

    /** Physical path is separately constrained by receipt(); explicit UID supports isolated Linux tests. */
    public static function backup(string $backup, string $expectedSumsSha, int $expectedUid): void
    {
        clearstatcache();
        if (realpath($backup) !== $backup || is_link($backup) || ! is_dir($backup)
            || fileowner($backup) !== $expectedUid || (fileperms($backup) & 0777) !== 0700) {
            throw new RuntimeException('Verified backup physical directory identity changed.');
        }
        foreach (['SHA256SUMS', 'database.dump', 'persistent-files.tar.gz', 'manifest.env'] as $name) {
            $file = $backup.'/'.$name;
            if (realpath($file) !== $file || is_link($file) || ! is_file($file) || fileowner($file) !== $expectedUid
                || (fileperms($file) & 0777) !== 0600 || filesize($file) < 1) {
                throw new RuntimeException('Verified backup file boundary changed.');
            }
        }
        $sums = file_get_contents($backup.'/SHA256SUMS');
        if ($sums === false || hash('sha256', $sums) !== $expectedSumsSha) {
            throw new RuntimeException('Verified backup manifest fingerprint changed.');
        }
        $verified = [];
        foreach (explode("\n", trim($sums)) as $line) {
            if (! preg_match('/^([a-f0-9]{64})  (database\.dump|persistent-files\.tar\.gz|manifest\.env)$/D', $line, $match)
                || isset($verified[$match[2]]) || hash_file('sha256', $backup.'/'.$match[2]) !== $match[1]) {
                throw new RuntimeException('Verified backup artifact hash changed.');
            }
            $verified[$match[2]] = true;
        }
        if (count($verified) !== 3) {
            throw new RuntimeException('Verified backup artifact set incomplete.');
        }
        $manifest = file_get_contents($backup.'/manifest.env');
        if ($manifest === false || $manifest !== 'BACKUP_FORMAT_VERSION=1'."\nCREATED_AT=".basename($backup)
            ."\nDATABASE_NAME=drive_phangan\nRELEASE_PATH=/srv/drive-phangan/releases/20261029T181820Z\n") {
            throw new RuntimeException('Verified backup source identity changed.');
        }
    }
}
