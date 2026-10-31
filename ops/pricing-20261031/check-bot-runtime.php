<?php

declare(strict_types=1);

// Existing Linux CI only. Real production lock/tree smoke; no artifact/deployment.
try {
    require __DIR__.'/BotRuntimeTree.php';
    if (getenv('CI') !== 'true' || PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
        throw new RuntimeException('Non-root Linux CI fixture only.');
    }
    $bot = realpath(dirname(__DIR__, 2).'/bot');
    if ($bot === false || realpath($bot.'/node_modules') !== $bot.'/node_modules' || is_link($bot.'/node_modules')) {
        throw new RuntimeException('Real CI module tree required.');
    }
    $modules = $bot.'/node_modules';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        if (! $entry->isLink()) {
            chmod($entry->getPathname(), $entry->isDir() ? 0555 : 0444);
        }
    }
    chmod($modules, 0555);
    $temporary = realpath(sys_get_temp_dir());
    if ($temporary === false) {
        throw new RuntimeException('Existing CI temporary root required.');
    }
    $old = $temporary.'/price-real-runtime-'.bin2hex(random_bytes(8));
    mkdir($old, 0700);
    try {
        $proof = BotRuntimeTree::capture($bot, $old, posix_geteuid(), posix_getegid());
        echo json_encode(['real_locked_linux_tree' => 'PASS', 'entries' => count($proof['entries']),
            'logical_bytes' => $proof['logical_bytes'], 'allocated_bytes' => $proof['allocated_bytes'],
            'lock_sha256' => $proof['lock_sha256']], JSON_THROW_ON_ERROR), "\n";
    } finally {
        rmdir($old);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Real CI dependency tree refused: '.$error->getMessage()."\n");
    exit(1);
}
