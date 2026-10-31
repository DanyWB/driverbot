<?php

namespace Tests\Unit;

use BotRuntimeTree;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once dirname(__DIR__, 3).'/ops/pricing-20261031/BotRuntimeTree.php';

class BotRuntimeTreeTest extends TestCase
{
    private string $root;

    private string $bot;

    private string $old;

    private int $uid;

    private int $gid;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('Real frozen Linux permissions/inodes/symlinks run on CI.');
        }
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            throw new RuntimeException('Existing absolute temporary root required.');
        }
        $this->root = $temporary.'/bot-runtime-fixture-'.bin2hex(random_bytes(8));
        $this->bot = $this->root.'/bot';
        $this->old = $this->root.'/old';
        $this->uid = posix_geteuid();
        $this->gid = posix_getegid();
        mkdir($this->bot.'/node_modules/pkg', 0700, true);
        mkdir($this->bot.'/node_modules/.bin', 0700);
        mkdir($this->old, 0700);
        file_put_contents($this->old.'/original.js', 'SYNTHETIC OLD MODULE');
        chmod($this->old.'/original.js', 0444);
        file_put_contents($this->bot.'/package.json', '{}');
        file_put_contents($this->bot.'/package-lock.json', '{"packages":{"node_modules/pkg":{"version":"1.0.0"}}}');
        file_put_contents($this->bot.'/node_modules/pkg/index.js', 'module.exports = 1;');
        file_put_contents($this->bot.'/node_modules/pkg/package.json', '{"name":"synthetic","version":"1.0.0"}');
        symlink('../pkg/index.js', $this->bot.'/node_modules/.bin/synthetic');
        foreach (['pkg/index.js', 'pkg/package.json'] as $name) {
            chmod($this->bot.'/node_modules/'.$name, 0444);
        }
        foreach (['pkg', '.bin', ''] as $name) {
            chmod($this->bot.'/node_modules'.($name === '' ? '' : '/'.$name), 0555);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && realpath($this->root) === $this->root && str_contains(basename($this->root), 'bot-runtime-fixture-')) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
                if (! $item->isLink() && $item->isDir()) {
                    chmod($item->getPathname(), 0700);
                }
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                if ($item->isLink() || $item->isFile()) {
                    unlink($item->getPathname());
                } else {
                    rmdir($item->getPathname());
                }
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_complete_frozen_inventory_records_hash_modes_inodes_and_internal_link(): void
    {
        $proof = BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
        $this->assertSame(hash('sha256', 'module.exports = 1;'), $proof['entries']['node_modules/pkg/index.js']['sha256']);
        $this->assertSame(0444, $proof['entries']['node_modules/pkg/index.js']['mode']);
        $this->assertSame('../pkg/index.js', $proof['entries']['node_modules/.bin/synthetic']['target']);
        $this->assertGreaterThan(0, $proof['allocated_bytes']);
        $this->assertSame($proof['entries'], BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid)['entries']);
    }

    public function test_escape_link_is_refused(): void
    {
        chmod($this->bot.'/node_modules/.bin', 0700);
        unlink($this->bot.'/node_modules/.bin/synthetic');
        symlink($this->old.'/original.js', $this->bot.'/node_modules/.bin/synthetic');
        chmod($this->bot.'/node_modules/.bin', 0555);
        $this->expectException(RuntimeException::class);
        BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
    }

    public function test_writable_module_is_refused(): void
    {
        chmod($this->bot.'/node_modules/pkg/index.js', 0644);
        $this->expectException(RuntimeException::class);
        BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
    }

    public function test_shared_old_inode_is_refused_without_changing_old_content(): void
    {
        chmod($this->bot.'/node_modules/pkg', 0700);
        unlink($this->bot.'/node_modules/pkg/index.js');
        link($this->old.'/original.js', $this->bot.'/node_modules/pkg/index.js');
        chmod($this->bot.'/node_modules/pkg', 0555);
        $this->expectException(RuntimeException::class);
        BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
    }

    public function test_install_hook_is_refused_even_though_installer_also_disables_hooks(): void
    {
        $file = $this->bot.'/node_modules/pkg/package.json';
        chmod($file, 0644);
        file_put_contents($file, '{"scripts":{"install":"echo never execute"}}');
        chmod($file, 0444);
        $this->expectException(RuntimeException::class);
        BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
    }

    public function test_published_resolve_style_package_json_fixture_is_data_not_lifecycle_root(): void
    {
        chmod($this->bot.'/node_modules/pkg', 0700);
        mkdir($this->bot.'/node_modules/pkg/test', 0700);
        file_put_contents($this->bot.'/node_modules/pkg/test/package.json', '{"scripts":{"postinstall":"lerna bootstrap"}}');
        chmod($this->bot.'/node_modules/pkg/test/package.json', 0444);
        chmod($this->bot.'/node_modules/pkg/test', 0555);
        chmod($this->bot.'/node_modules/pkg', 0555);
        $proof = BotRuntimeTree::capture($this->bot, $this->old, $this->uid, $this->gid);
        $this->assertSame('file', $proof['entries']['node_modules/pkg/test/package.json']['type']);
        $this->assertNotEmpty($proof['entries']['node_modules/pkg/test/package.json']['sha256']);
    }
}
