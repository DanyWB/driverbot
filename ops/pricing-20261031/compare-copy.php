<?php

declare(strict_types=1);

// Read-only proof: independent copies, exact old tree except one PHP patch,
// explicit new release manifest and this reviewed operation's new helpers.
try {
    [$script, $old, $new, $source] = $argv;
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
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $relative = substr($entry->getPathname(), strlen($old) + 1);
        $knownEntries[$relative] = true;
        $copy = $new.'/'.$relative;
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
