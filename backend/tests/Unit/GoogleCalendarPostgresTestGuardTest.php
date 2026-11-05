<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\GoogleCalendarPostgresTestGuard;

final class GoogleCalendarPostgresTestGuardTest extends TestCase
{
    public function test_explicit_loopback_postgres_test_target_is_accepted(): void
    {
        [$environment, $configuration] = $this->safeConfiguration();

        GoogleCalendarPostgresTestGuard::assertSafe($environment, $configuration, false, 'testing');

        $this->assertTrue(true);
    }

    #[DataProvider('unsafeEnvironmentProvider')]
    public function test_unsafe_environment_is_rejected(array $changes): void
    {
        [$environment, $configuration] = $this->safeConfiguration();
        $environment = array_replace($environment, $changes);

        $this->expectException(RuntimeException::class);
        GoogleCalendarPostgresTestGuard::assertSafe($environment, $configuration, false, 'testing');
    }

    public static function unsafeEnvironmentProvider(): array
    {
        return [
            'missing opt-in' => [['GOOGLE_CALENDAR_PG_RACE_TESTING' => null]],
            'opt-in is not exact' => [['GOOGLE_CALENDAR_PG_RACE_TESTING' => 'true']],
            'non-postgresql driver' => [['DB_CONNECTION' => 'sqlite']],
            'remote host' => [['DB_HOST' => 'db.example.test']],
            'loopback alias rejected' => [['DB_HOST' => 'localhost']],
            'uppercase suffix' => [['DB_DATABASE' => 'phangan_TEST']],
            'other database rejected' => [['DB_DATABASE' => 'drive_phangan']],
            'confirmation mismatch' => [['GOOGLE_CALENDAR_PG_RACE_DATABASE' => 'other_test']],
            'wrong role rejected' => [['DB_USERNAME' => 'postgres']],
            'wrong port rejected' => [['DB_PORT' => '5433']],
            'non-local SSL mode rejected' => [['DB_SSLMODE' => 'prefer']],
            'database URL override' => [['DB_URL' => 'pgsql://remote/drive_phangan']],
            'wrong app environment' => [['APP_ENV' => 'production']],
        ];
    }

    public function test_cached_configuration_is_rejected(): void
    {
        [$environment, $configuration] = $this->safeConfiguration();

        $this->expectException(RuntimeException::class);
        GoogleCalendarPostgresTestGuard::assertSafe($environment, $configuration, true, 'testing');
    }

    public function test_effective_database_config_must_match_explicit_target(): void
    {
        [$environment, $configuration] = $this->safeConfiguration();
        $configuration['connections']['pgsql']['database'] = 'drive_phangan';

        $this->expectException(RuntimeException::class);
        GoogleCalendarPostgresTestGuard::assertSafe($environment, $configuration, false, 'testing');
    }

    public function test_resolved_application_environment_must_be_testing(): void
    {
        [$environment, $configuration] = $this->safeConfiguration();

        $this->expectException(RuntimeException::class);
        GoogleCalendarPostgresTestGuard::assertSafe($environment, $configuration, false, 'production');
    }

    public function test_process_preflight_initializes_laravel_before_loading_database_config(): void
    {
        $basePath = dirname(__DIR__, 2);
        $phpCode = '$basePath = '.var_export($basePath, true).'; '
            .'require $basePath . DIRECTORY_SEPARATOR . "vendor/autoload.php"; '
            .'\\Tests\\Support\\GoogleCalendarPostgresTestGuard::assertProcessIsSafe($basePath); '
            .'echo "preflight-ok";';
        $process = new Process([PHP_BINARY, '-r', $phpCode], $basePath, [
            'APP_ENV' => 'testing',
            'GOOGLE_CALENDAR_PG_RACE_TESTING' => '1',
            'GOOGLE_CALENDAR_PG_RACE_DATABASE' => 'drive_phangan_gcal_test',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'drive_phangan_gcal_test',
            'DB_USERNAME' => 'gcal_test',
            'DB_PASSWORD' => 'guard-regression-test-password',
            'DB_URL' => '',
            'DB_SSLMODE' => 'disable',
        ], null, 10);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('preflight-ok', trim($process->getOutput()));
    }

    /** @return array{array<string, string|null>, array<string, mixed>} */
    private function safeConfiguration(): array
    {
        $environment = [
            'APP_ENV' => 'testing',
            'GOOGLE_CALENDAR_PG_RACE_TESTING' => '1',
            'GOOGLE_CALENDAR_PG_RACE_DATABASE' => 'drive_phangan_gcal_test',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'drive_phangan_gcal_test',
            'DB_USERNAME' => 'gcal_test',
            'DB_PASSWORD' => 'test_password',
            'DB_URL' => '',
            'DB_SSLMODE' => 'disable',
        ];

        $configuration = [
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'url' => '',
                    'host' => '127.0.0.1',
                    'port' => '5432',
                    'database' => 'drive_phangan_gcal_test',
                    'username' => 'gcal_test',
                    'password' => 'test_password',
                    'sslmode' => 'disable',
                ],
            ],
        ];

        return [$environment, $configuration];
    }
}
