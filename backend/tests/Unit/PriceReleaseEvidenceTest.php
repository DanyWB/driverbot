<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PriceReleaseEvidence;
use RuntimeException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/ops/pricing-20261031/PriceReleaseEvidence.php';

class PriceReleaseEvidenceTest extends TestCase
{
    public function test_linux_bash_parses_reviewed_wrapper_without_executing_it(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux bash syntax is checked by the existing Linux CI suite.');
        }
        $process = new Process(['bash', '-n', dirname(__DIR__, 3).'/ops/pricing-20261031/release.sh']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_receipt_binds_exact_bytes_baseline_revision_and_fixed_backup_root(): void
    {
        $baseline = '{"synthetic":true}';
        $receipt = [
            'format' => 'PREPRICE-VERIFIED-BACKUP-1', 'baseline_sha256' => hash('sha256', $baseline),
            'source_revision' => '6792e28929cfe20b1cf3c9678929e27bfaf61e80',
            'path' => '/srv/drive-phangan/backups/20261031T120000Z', 'sha256sums_sha256' => str_repeat('a', 64),
        ];
        $json = json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertSame($receipt['path'], PriceReleaseEvidence::receipt($json, $baseline, hash('sha256', $json))['path']);
        foreach (['hash', 'baseline', 'revision', 'path'] as $case) {
            $changed = $receipt;
            if ($case === 'revision') {
                $changed['source_revision'] = str_repeat('0', 40);
            } elseif ($case === 'path') {
                $changed['path'] = '/tmp/fake-backup';
            }
            $changedJson = json_encode($changed, JSON_THROW_ON_ERROR);
            try {
                PriceReleaseEvidence::receipt($changedJson, $case === 'baseline' ? '{}' : $baseline, $case === 'hash' ? str_repeat('0', 64) : hash('sha256', $changedJson));
                $this->fail('Changed receipt evidence must refuse.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('identity required', $e->getMessage());
            }
        }
    }

    public function test_linux_physical_backup_refuses_symlink_modes_tamper_and_wrong_owner(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('Real POSIX ownership/mode/symlink fixture runs on Linux CI only.');
        }
        $parent = sys_get_temp_dir().'/price-release-evidence-'.bin2hex(random_bytes(8));
        $backup = $parent.'/20261031T120000Z';
        mkdir($backup, 0700, true);
        $names = ['database.dump', 'persistent-files.tar.gz', 'manifest.env', 'SHA256SUMS'];
        try {
            file_put_contents($backup.'/database.dump', 'SYNTHETIC DUMP ONLY');
            file_put_contents($backup.'/persistent-files.tar.gz', 'SYNTHETIC ARCHIVE ONLY');
            file_put_contents($backup.'/manifest.env', "BACKUP_FORMAT_VERSION=1\nCREATED_AT=20261031T120000Z\nDATABASE_NAME=drive_phangan\nRELEASE_PATH=/srv/drive-phangan/releases/20261029T181820Z\n");
            $sums = '';
            foreach (array_slice($names, 0, 3) as $name) {
                $sums .= hash_file('sha256', $backup.'/'.$name).'  '.$name."\n";
            }
            file_put_contents($backup.'/SHA256SUMS', $sums);
            foreach ($names as $name) {
                chmod($backup.'/'.$name, 0600);
            }
            $sha = hash('sha256', $sums);
            PriceReleaseEvidence::backup($backup, $sha, posix_geteuid());
            $this->addToAssertionCount(1);
            foreach (['mode', 'hash', 'owner', 'symlink'] as $case) {
                if ($case === 'mode') {
                    chmod($backup.'/database.dump', 0644);
                } elseif ($case === 'hash') {
                    file_put_contents($backup.'/database.dump', 'TAMPERED');
                } elseif ($case === 'symlink') {
                    unlink($backup.'/database.dump');
                    symlink($backup.'/manifest.env', $backup.'/database.dump');
                }
                try {
                    PriceReleaseEvidence::backup($backup, $sha, $case === 'owner' ? posix_geteuid() + 1 : posix_geteuid());
                    $this->fail('Invalid physical backup must refuse.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('Verified backup', $e->getMessage());
                }
                if ($case === 'symlink') {
                    unlink($backup.'/database.dump');
                }
                file_put_contents($backup.'/database.dump', 'SYNTHETIC DUMP ONLY');
                chmod($backup.'/database.dump', 0600);
            }
        } finally {
            foreach ($names as $name) {
                if (is_file($backup.'/'.$name) || is_link($backup.'/'.$name)) {
                    unlink($backup.'/'.$name);
                }
            }
            rmdir($backup);
            rmdir($parent);
        }
    }
}
