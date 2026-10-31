<?php

declare(strict_types=1);

/** Read-only inventory of a freshly npm-ci-installed, frozen Linux module tree. */
final class BotRuntimeTree
{
    /** @return array<string,mixed> */
    public static function capture(string $bot, string $oldModules, int $uid = 0, int $gid = 0): array
    {
        $modules = $bot.'/node_modules';
        if (realpath($bot) !== $bot || realpath($modules) !== $modules || is_link($modules)
            || realpath($oldModules) !== $oldModules || is_link($oldModules) || $modules === $oldModules) {
            throw new RuntimeException('Independent real module roots required.');
        }
        $lockJson = file_get_contents($bot.'/package-lock.json');
        if ($lockJson === false) {
            throw new RuntimeException('Reviewed lock unreadable.');
        }
        $lock = json_decode($lockJson, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($lock['packages'] ?? null)) {
            throw new RuntimeException('Reviewed installed package root list missing.');
        }
        $oldInodes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oldModules, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (! $item->isLink()) {
                $oldInodes[$item->getDev().':'.$item->getInode()] = true;
            }
        }
        $entries = [];
        $seen = [];
        $logical = 0;
        $allocated = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $file = $item->getPathname();
            $relative = substr($file, strlen($bot) + 1);
            $stat = lstat($file);
            if ($stat === false || ! preg_match('~^node_modules(?:/[A-Za-z0-9@._+-]+)+$~D', $relative)
                || in_array('..', explode('/', $relative), true) || $stat['uid'] !== $uid || $stat['gid'] !== $gid) {
                throw new RuntimeException('Unexpected module path/owner.');
            }
            $mode = $stat['mode'] & 0777;
            $record = ['mode' => $mode, 'inode' => $stat['ino'], 'device' => $stat['dev'], 'uid' => $stat['uid'], 'gid' => $stat['gid']];
            $allocated += $stat['blocks'] * 512;
            if ($item->isLink()) {
                $target = readlink($file);
                $resolved = realpath($file);
                if ($target === false || $resolved === false || ! preg_match('~^[A-Za-z0-9@._+/-]+$~D', $target)
                    || str_starts_with($target, '/') || ! str_starts_with($resolved, $modules.'/') || $mode !== 0777) {
                    throw new RuntimeException('Module symlink escapes frozen root.');
                }
                $record += ['type' => 'link', 'target' => $target];
            } else {
                $key = $stat['dev'].':'.$stat['ino'];
                if (isset($seen[$key]) || isset($oldInodes[$key]) || realpath($file) !== $file) {
                    throw new RuntimeException('Module inode shared/linked with another entry or old release.');
                }
                $seen[$key] = true;
                if ($item->isDir() && $mode === 0555) {
                    $record += ['type' => 'dir'];
                } elseif ($item->isFile() && $mode === 0444 && ! preg_match('/\.(?:node|dll|exe|so|dylib)$/iD', $file)) {
                    $bytes = $item->getSize();
                    $stream = fopen($file, 'rb');
                    if ($stream === false) {
                        throw new RuntimeException('Module content unreadable.');
                    }
                    $magic = fread($stream, 4);
                    fclose($stream);
                    if ($magic === false || $magic === "\x7fELF" || str_starts_with($magic, 'MZ')) {
                        throw new RuntimeException('Native dependency file requires separate review.');
                    }
                    $sha = hash_file('sha256', $file);
                    if ($sha === false) {
                        throw new RuntimeException('Module content unreadable.');
                    }
                    $logical += $bytes;
                    // Published packages can contain package.json TEST FIXTURES
                    // (resolve/multirepo has a lerna postinstall). Only lock-listed
                    // installed package roots are npm lifecycle entry points.
                    if ($item->getFilename() === 'package.json' && isset($lock['packages'][dirname($relative)])) {
                        $json = file_get_contents($file);
                        if ($json === false) {
                            throw new RuntimeException('Module metadata unreadable.');
                        }
                        $package = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                        foreach (['preinstall', 'install', 'postinstall'] as $hook) {
                            if (isset($package['scripts'][$hook])) {
                                throw new RuntimeException('Installed runtime lifecycle hook requires separate review.');
                            }
                        }
                    }
                    $record += ['type' => 'file', 'bytes' => $bytes, 'sha256' => $sha];
                } else {
                    throw new RuntimeException('Unfrozen/native/unsupported module entry.');
                }
            }
            $entries[$relative] = $record;
            if (count($entries) > 20000 || $logical > 335544320 || $allocated > 335544320) {
                throw new RuntimeException('320MiB dependency tree budget exceeded.');
            }
        }
        $root = lstat($modules);
        if ($root === false || ($root['mode'] & 0777) !== 0555 || $root['uid'] !== $uid || $root['gid'] !== $gid || $entries === []
            || $allocated + $root['blocks'] * 512 > 335544320) {
            throw new RuntimeException('Frozen dependency root missing.');
        }
        ksort($entries, SORT_STRING);

        return ['format' => 'PRICE-BOT-INSTALLED-TREE-1', 'package_sha256' => hash_file('sha256', $bot.'/package.json'),
            'lock_sha256' => hash_file('sha256', $bot.'/package-lock.json'), 'logical_bytes' => $logical,
            'allocated_bytes' => $allocated + $root['blocks'] * 512, 'entries' => $entries];
    }
}
