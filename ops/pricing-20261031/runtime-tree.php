<?php

declare(strict_types=1);

try {
    require __DIR__.'/BotRuntimeTree.php';
    $bot = $argv[1] ?? '';
    if (PHP_OS_FAMILY !== 'Linux' || ! preg_match('~^/srv/drive-phangan/releases/[0-9]{8}T[0-9]{6}Z/bot$~D', $bot)
        || $bot === '/srv/drive-phangan/releases/20261029T181820Z/bot' || realpath($bot) !== $bot) {
        throw new RuntimeException('New independent production candidate only.');
    }
    echo json_encode(BotRuntimeTree::capture($bot, '/srv/drive-phangan/releases/20261029T181820Z/bot/node_modules'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Frozen Linux bot dependency verification refused.\n");
    exit(1);
}
