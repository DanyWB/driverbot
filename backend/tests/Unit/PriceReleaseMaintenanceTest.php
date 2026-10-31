<?php

namespace Tests\Unit;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\CacheBasedMaintenanceMode;
use Illuminate\Foundation\FileBasedMaintenanceMode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PriceReleaseMaintenanceTest extends TestCase
{
    private Container $previous;

    private Application $application;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            throw new RuntimeException('Existing isolated temporary root required.');
        }
        $this->fixture = $temporary.'/price-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->fixture.'/storage/framework', 0700, true);
        $this->application = new Application($this->fixture);
        $this->application->useStoragePath($this->fixture.'/storage');
        $this->application->instance('config', new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture) && realpath($this->fixture) === $this->fixture && str_starts_with(basename($this->fixture), 'price-maintenance-')) {
            $marker = $this->fixture.'/storage/framework/down';
            if (is_file($marker)) {
                unlink($marker);
            }
            rmdir($this->fixture.'/storage/framework');
            rmdir($this->fixture.'/storage');
            rmdir($this->fixture);
        }
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_actual_cache_driver_active_and_inactive_without_a_file_marker(): void
    {
        $mode = new CacheBasedMaintenanceMode(new CacheManager($this->application), 'array', 'SYNTHETIC-PRICE-RELEASE');
        $this->application->instance(MaintenanceMode::class, $mode);
        $this->assertFalse($this->application->isDownForMaintenance());
        $mode->activate(['retry' => 30]);
        $this->assertTrue($this->application->isDownForMaintenance());
        $this->assertFileDoesNotExist($this->fixture.'/storage/framework/down');
        $mode->deactivate();
        $this->assertFalse($this->application->isDownForMaintenance());
    }

    public function test_actual_file_driver_active_and_inactive_uses_the_same_canonical_api(): void
    {
        $mode = new FileBasedMaintenanceMode;
        $this->application->instance(MaintenanceMode::class, $mode);
        $this->assertFalse($this->application->isDownForMaintenance());
        $mode->activate(['retry' => 30]);
        $this->assertFileExists($this->fixture.'/storage/framework/down');
        $this->assertTrue($this->application->isDownForMaintenance());
        $mode->deactivate();
        $this->assertFalse($this->application->isDownForMaintenance());
        $this->assertFileDoesNotExist($this->fixture.'/storage/framework/down');
    }

    public function test_both_entrypoints_keep_the_canonical_guard_and_existing_security_boundaries(): void
    {
        $runner = file_get_contents(dirname(__DIR__, 3).'/ops/pricing-20261031/run.php');
        $repair = file_get_contents(dirname(__DIR__, 3).'/ops/pricing-20261031/repair.php');
        $this->assertIsString($runner);
        $this->assertIsString($repair);
        $this->assertStringContainsString('! $app->isDownForMaintenance()', $runner);
        $this->assertStringNotContainsString("storage_path('framework/down')", $runner);
        foreach (['FINAL_PRICES_EXECUTION_GO', 'FINAL_PRICES_BACKUP_VERIFIED', 'PriceReleaseEvidence::backup', 'posix_geteuid', 'current_database()', 'realpath(storage_path())'] as $guard) {
            $this->assertStringContainsString($guard, $runner);
        }
        $this->assertStringContainsString("require __DIR__.'/run.php'", $repair);
    }
}
