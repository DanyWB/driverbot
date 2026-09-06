<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeploymentHardeningTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
    }

    public function test_release_scripts_keep_required_safety_controls(): void
    {
        $common = $this->read('deploy/scripts/common.sh');
        $deploy = $this->read('deploy/scripts/deploy-release.sh');
        $rollback = $this->read('deploy/scripts/rollback-release.sh');
        $smoke = $this->read('deploy/scripts/smoke.sh');

        $this->assertStringContainsString('acquire_deploy_lock()', $common);
        $this->assertStringContainsString('assert_minimum_free_space()', $common);
        $this->assertStringContainsString('RELEASE_RETENTION_COUNT', $common);
        $this->assertStringContainsString('Refusing to remove the current release', $common);
        $this->assertStringContainsString('seal_release_read_only()', $common);
        $this->assertStringContainsString('! -type l -exec chown root:root', $common);

        $this->assertStringContainsString('DEPLOY_MIN_FREE_MB', $deploy);
        $this->assertStringContainsString('DEPLOY_CUTOVER_MIN_FREE_MB', $deploy);
        $this->assertStringContainsString('release-manifest.env', $deploy);
        $this->assertStringContainsString('SOURCE_REVISION=', $deploy);
        $this->assertStringContainsString('rm -rf -- "${RELEASE_DIR}/backend/node_modules"', $deploy);
        $this->assertStringContainsString("--exclude='/driverbot.sql'", $deploy);
        $this->assertStringContainsString("--exclude='*.sql.gz'", $deploy);
        $this->assertStringContainsString('Removing incomplete release', $deploy);
        $this->assertStringContainsString('audit --locked --no-dev --no-interaction', $deploy);
        $this->assertSame(2, substr_count($deploy, 'audit --omit=dev'));
        $this->assertStringContainsString('seal_release_read_only "$RELEASE_DIR"', $deploy);
        $this->assertStringContainsString('ALLOW_PRE_CUTOVER_SMOKE is not permitted during deploy cutover', $deploy);

        $this->assertStringContainsString('ORIGINAL_RELEASE=', $rollback);
        $this->assertStringContainsString('Rollback smoke failed; restoring original application symlink', $rollback);
        $this->assertStringContainsString('ALLOW_PRE_CUTOVER_SMOKE is not permitted during rollback cutover', $rollback);

        $this->assertStringContainsString('ALLOW_PRE_CUTOVER_SMOKE', $smoke);
        $this->assertStringContainsString('drive-phangan-worker-notifications.service', $common);
        $this->assertStringContainsString('systemctl is-active --quiet', $smoke);
        $this->assertStringContainsString('run release:smoke', $smoke);
        $this->assertStringNotContainsString('RUN_BOT_API_SMOKE', $smoke);
    }

    public function test_runtime_templates_bound_memory_and_restart_storms(): void
    {
        $fpm = $this->read('deploy/php-fpm/drive-phangan.conf');
        $this->assertStringContainsString('pm.max_children = 6', $fpm);

        $queue = $this->read('backend/config/queue.php');
        $backendEnvironment = $this->read('deploy/env/backend.production.example');
        $this->assertStringContainsString("env('REDIS_QUEUE_RETRY_AFTER', 120)", $queue);
        $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER=120', $backendEnvironment);

        $bot = $this->read('deploy/systemd/drive-phangan-bot.service');
        $this->assertStringContainsString('ExecStartPre=/usr/bin/npm run release:smoke', $bot);

        $defaultWorker = $this->read('deploy/systemd/drive-phangan-worker-default.service');
        $this->assertStringContainsString('--timeout=90', $defaultWorker);

        foreach ([
            'drive-phangan-bot.service',
            'drive-phangan-scheduler.service',
            'drive-phangan-worker-default.service',
            'drive-phangan-worker-notifications.service',
        ] as $unit) {
            $contents = $this->read('deploy/systemd/'.$unit);
            $this->assertStringContainsString('StartLimitIntervalSec=600', $contents, $unit);
            $this->assertStringContainsString('StartLimitBurst=5', $contents, $unit);
            $this->assertStringContainsString('RestartSec=30s', $contents, $unit);
            $this->assertStringContainsString('Restart=on-failure', $contents, $unit);
        }

        $backup = $this->read('deploy/scripts/backup.sh');
        $this->assertStringContainsString('BACKUP_OWNER="${BACKUP_OWNER:-drive-phangan}"', $backup);
        $this->assertStringContainsString('chown -R "${BACKUP_OWNER}:${BACKUP_GROUP}" "$FINAL_DIR"', $backup);
        $this->assertGreaterThanOrEqual(3, substr_count($backup, 'prune_expired_backups'));
        $this->assertStringContainsString('remove_backup_if_safe "$old_backup"', $backup);
    }

    public function test_release_shell_scripts_parse_and_safety_scenarios_pass(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Deployment shell tests run on Linux CI and deployment hosts.');
        }

        $scripts = glob($this->projectRoot.'/deploy/scripts/*.sh') ?: [];
        $scripts[] = $this->projectRoot.'/deploy/tests/release-hardening.test.sh';

        $syntax = new Process(array_merge(['bash', '-n'], $scripts));
        $syntax->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput().$syntax->getOutput());

        $safety = new Process(['bash', $this->projectRoot.'/deploy/tests/release-hardening.test.sh']);
        $safety->setTimeout(30);
        $safety->run();
        $this->assertSame(0, $safety->getExitCode(), $safety->getErrorOutput().$safety->getOutput());
    }

    private function read(string $relativePath): string
    {
        $path = $this->projectRoot.'/'.$relativePath;
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, $path);

        return $contents;
    }
}
