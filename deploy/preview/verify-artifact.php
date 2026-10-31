<?php

declare(strict_types=1);

try {
    $mode = $argv[1] ?? '';
    $root = realpath($argv[2] ?? '');
    if ($root === false || is_link($argv[2] ?? '') || ! str_starts_with($root, '/srv/drive-phangan-preview/releases/')) {
        throw new RuntimeException('Artifact root is not an isolated preview release.');
    }
    $manifestFile = $root.'/artifact-manifest.json';
    if (! is_file($manifestFile) || filesize($manifestFile) > 2000000) {
        throw new RuntimeException('Artifact manifest missing or too large.');
    }
    $manifest = json_decode(file_get_contents($manifestFile), true, 30, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 'WEBSITE-PREVIEW-ARTIFACT-1' || ($manifest['source_reviewed_local_changes'] ?? null) !== true
        || ! preg_match('/^preview-20261031-[a-f0-9]{8}$/D', $manifest['preview_release_id'] ?? '')
        || ! preg_match('/^[a-f0-9]{32}$/D', $manifest['instance_id'] ?? '')
        || $manifest['host'] !== 'drive-preview.2-26-60-62.sslip.io' || ! is_array($manifest['files'] ?? null)
        || count($manifest['files']) > 5000 || ($manifest['package_bytes'] ?? PHP_INT_MAX) > 67108864 || ($manifest['media_bytes'] ?? PHP_INT_MAX) > 25000000) {
        throw new RuntimeException('Artifact provenance/bounds invalid.');
    }
    foreach (['source_base_revision' => 40, 'composer_lock_sha256' => 64, 'source_snapshot_sha256' => 64, 'pricing_source_sha256' => 64, 'mapping_sha256' => 64, 'media_manifest_sha256' => 64, 'backend_env_sha256' => 64, 'htpasswd_sha256' => 64, 'probe_curl_sha256' => 64] as $key => $length) {
        if (! is_string($manifest[$key] ?? null) || ! preg_match('/^[a-f0-9]{'.$length.'}$/D', $manifest[$key])) {
            throw new RuntimeException('Artifact fingerprints invalid.');
        }
    }
    $expected = ['artifact-manifest.json' => true];
    $bytes = 0;
    foreach ($manifest['files'] as $item) {
        $path = $item['path'] ?? '';
        if (! is_string($path) || ! preg_match('~^(?:backend/(?:app|config|routes|database|lang|resources/views|public/build|public/website)/[A-Za-z0-9_./-]+|backend/(?:artisan|composer\.json|composer\.lock|bootstrap/(?:app|providers)\.php|public/index\.php)|deploy/preview/[A-Za-z0-9_.-]+|inputs/(?:catalog-snapshot|pricing-source|mapping|optimized-manifest)\.json|media/vehicles/[1-9][0-9]*/[a-f0-9]{64}\.webp)$~D', $path)
            || str_contains($path, '..') || str_contains($path, '/.env') || isset($expected[$path])
            || ! is_int($item['bytes'] ?? null) || $item['bytes'] < 0 || $item['bytes'] > 25000000
            || ! preg_match('/^[a-f0-9]{64}$/D', $item['sha256'] ?? '')) {
            throw new RuntimeException('Artifact path/file bounds invalid.');
        }
        $file = $root.'/'.$path;
        if (! is_file($file) || is_link($file) || realpath($file) !== $file || filesize($file) !== $item['bytes'] || hash_file('sha256', $file) !== $item['sha256']) {
            throw new RuntimeException('Artifact file fingerprint mismatch.');
        }
        $expected[$path] = true;
        $bytes += $item['bytes'];
    }
    if ($bytes !== $manifest['package_bytes']) {
        throw new RuntimeException('Artifact byte total differs.');
    }
    $directories = [];
    foreach (array_keys($expected) as $path) {
        while (($path = dirname($path)) !== '.') {
            $directories[$path] = true;
        }
    }
    $generated = ['backend/bootstrap/cache/packages.php', 'backend/bootstrap/cache/services.php', 'backend/bootstrap/cache/config.php', 'backend/bootstrap/cache/routes-v7.php'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if ($mode === 'prepared') {
            if ($path === 'backend/.env') {
                if (! $file->isLink() || realpath($file->getPathname()) !== '/srv/drive-phangan-preview/shared/private/backend.env') {
                    throw new RuntimeException('Prepared environment link is invalid.');
                }

                continue;
            }
            if ($path === 'backend/vendor' || str_starts_with($path, 'backend/vendor/')) {
                $resolved = realpath($file->getPathname());
                if ($resolved === false || ($path === 'backend/vendor' && $file->isLink())
                    || ($resolved !== $root.'/backend/vendor' && ! str_starts_with($resolved, $root.'/backend/vendor/'))) {
                    throw new RuntimeException('Prepared vendor escapes its independent root.');
                }

                continue;
            }
            if (($file->isDir() && $path === 'backend/bootstrap/cache') || ($file->isFile() && in_array($path, $generated, true))) {
                if ($file->isLink() || realpath($file->getPathname()) !== $file->getPathname()) {
                    throw new RuntimeException('Prepared bootstrap cache is linked.');
                }

                continue;
            }
        }
        if ($file->isLink()) {
            throw new RuntimeException('Artifact links are forbidden.');
        }
        if (($file->isFile() && ! isset($expected[$path])) || ($file->isDir() && ! isset($directories[$path]))) {
            throw new RuntimeException('Artifact contains an unexpected file.');
        }
    }
    if ($mode === 'metadata') {
        foreach (['instance_id', 'preview_release_id', 'host', 'package_bytes', 'media_bytes', 'composer_lock_sha256', 'backend_env_sha256', 'htpasswd_sha256'] as $key) {
            echo $manifest[$key], "\n";
        }
    } elseif ($mode === 'verify' || $mode === 'prepared') {
        echo 'Reviewed local-change artifact verified; parent '.$manifest['source_base_revision'].'; release '.$manifest['preview_release_id']."\n";
    } else {
        throw new RuntimeException('Unsupported verification mode.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Preview artifact validation refused.\n");
    exit(1);
}
