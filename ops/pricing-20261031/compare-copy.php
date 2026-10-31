<?php

declare(strict_types=1);

// Read-only proof: independent copies, exact old tree except one PHP patch,
// explicit new release manifest and this reviewed operation's new helpers.
try {
    [$script, $old, $new, $source] = array_slice($argv, 0, 4);
    $runtimeProof = $argv[4] ?? null;
    $runtimeSha = $argv[5] ?? null;
    if (! preg_match('~^/srv/drive-phangan/releases/[0-9]{8}T[0-9]{6}Z$~D', $new)
        || $old !== '/srv/drive-phangan/releases/20261029T181820Z' || $new === $old
        || realpath($old) !== $old || realpath($new) !== $new || is_link($new)
        || realpath($source) !== '/srv/drive-phangan/source-20261018') {
        throw new RuntimeException('Copy boundary mismatch.');
    }
    $files = 0;
    $oldHashes = [];
    $knownEntries = [];
    $allowedChanges = ['backend/app/Domain/Pricing/Services/PricingSettings.php', 'release-manifest.env'];
    if ($runtimeProof !== null) {
        $expectedProof = '/srv/drive-phangan/price-release-20261031-operation-'.basename($new).'/bot-runtime-tree.json';
        if ($runtimeProof !== $expectedProof || realpath($runtimeProof) !== $runtimeProof || is_link($runtimeProof)
            || ! is_string($runtimeSha) || ! preg_match('/^[a-f0-9]{64}$/D', $runtimeSha) || filesize($runtimeProof) > 8_000_000) {
            throw new RuntimeException('Protected runtime inventory identity mismatch.');
        }
        $json = file_get_contents($runtimeProof);
        if ($json === false || hash('sha256', $json) !== $runtimeSha) {
            throw new RuntimeException('Runtime inventory fingerprint mismatch.');
        }
        require __DIR__.'/BotRuntimeTree.php';
        $expected = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $actual = BotRuntimeTree::capture($new.'/bot', $old.'/bot/node_modules');
        foreach (['format', 'package_sha256', 'lock_sha256', 'logical_bytes', 'entries'] as $key) {
            if (($expected[$key] ?? null) !== $actual[$key]) {
                throw new RuntimeException('Installed dependency contents/modes/inodes changed.');
            }
        }
        foreach (['package.json', 'package-lock.json'] as $name) {
            if (hash_file('sha256', $new.'/bot/'.$name) !== hash_file('sha256', $source.'/bot/'.$name)) {
                throw new RuntimeException('Reviewed bot package source differs.');
            }
        }
        $allowedChanges = [...$allowedChanges, 'bot/package.json', 'bot/package-lock.json'];
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $relative = substr($entry->getPathname(), strlen($old) + 1);
        $knownEntries[$relative] = true;
        $copy = $new.'/'.$relative;
        if ($runtimeProof !== null && ($relative === 'bot/node_modules' || str_starts_with($relative, 'bot/node_modules/'))) {
            if (! $entry->isLink() && $entry->isFile()) {
                $oldHashes[$relative] = hash_file('sha256', $entry->getPathname());
                $files++;
            }

            continue;
        }
        if ($entry->isLink()) {
            if (! is_link($copy) || readlink($copy) !== readlink($entry->getPathname())) {
                throw new RuntimeException('Copied runtime symlink differs.');
            }
        } elseif ($entry->isDir()) {
            if (! is_dir($copy) || is_link($copy)) {
                throw new RuntimeException('Copied directory differs.');
            }
        } elseif ($entry->isFile()) {
            $originalHash = hash_file('sha256', $entry->getPathname());
            if (! is_file($copy) || is_link($copy) || fileinode($copy) === $entry->getInode()
                || (! in_array($relative, $allowedChanges, true) && hash_file('sha256', $copy) !== $originalHash)) {
                throw new RuntimeException('Copy is changed/shared/missing.');
            }
            $oldHashes[$relative] = $originalHash;
            $files++;
        } else {
            throw new RuntimeException('Unsupported source entry.');
        }
    }
    $patch = 'backend/app/Domain/Pricing/Services/PricingSettings.php';
    if (hash_file('sha256', $new.'/'.$patch) !== hash_file('sha256', $source.'/'.$patch)) {
        throw new RuntimeException('Reviewed runtime PHP differs.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($new, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $relative = substr($entry->getPathname(), strlen($new) + 1);
        if ($runtimeProof !== null && ($relative === 'bot/node_modules' || str_starts_with($relative, 'bot/node_modules/'))) {
            continue; // Complete tree was independently verified above, including extra links/files.
        }
        if (isset($knownEntries[$relative])) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Unexpected added runtime symlink.');
        }
        if ($entry->isDir() && in_array($relative, ['ops', 'ops/pricing-20261031'], true)) {
            continue;
        }
        if (! $entry->isFile() || ! preg_match('~^ops/pricing-20261031/[A-Za-z0-9_-]+\.(?:php|sh)$~D', $relative)
            || ! is_file($source.'/'.$relative) || hash_file('sha256', $entry->getPathname()) !== hash_file('sha256', $source.'/'.$relative)) {
            throw new RuntimeException('Unexpected added runtime entry.');
        }
    }
    ksort($oldHashes);
    echo json_encode(['files' => $files, 'independent_copy' => true, 'old_tree_sha256' => hash('sha256', json_encode($oldHashes, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Independent release copy verification refused.\n");
    exit(1);
}
