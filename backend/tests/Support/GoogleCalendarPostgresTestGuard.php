<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Pure preflight checks for the destructive PostgreSQL RefreshDatabase suite. */
final class GoogleCalendarPostgresTestGuard
{
    /** @param array<string, string|null> $environment @param array<string, mixed> $databaseConfig */
    public static function assertSafe(
        array $environment,
        array $databaseConfig,
        bool $configurationCached,
        ?string $resolvedAppEnvironment = null,
    ): void {
        self::assertEnvironmentSafe($environment, $configurationCached, $resolvedAppEnvironment);

        $connections = $databaseConfig['connections'] ?? null;
        $pgsql = is_array($connections) ? ($connections['pgsql'] ?? null) : null;
        if (($databaseConfig['default'] ?? null) !== 'pgsql' || ! is_array($pgsql) || ($pgsql['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Effective Laravel database configuration is not PostgreSQL.');
        }

        if (($pgsql['url'] ?? null) !== null && ($pgsql['url'] ?? '') !== '') {
            throw new RuntimeException('Effective Laravel PostgreSQL configuration contains a DB URL.');
        }

        $expectedValues = [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'drive_phangan_gcal_test',
            'username' => 'gcal_test',
            'password' => $environment['DB_PASSWORD'],
            'sslmode' => 'disable',
        ];

        foreach ($expectedValues as $configKey => $expected) {
            $resolved = $pgsql[$configKey] ?? null;
            if (! is_scalar($resolved) || (string) $resolved !== (string) $expected) {
                throw new RuntimeException("Effective Laravel PostgreSQL {$configKey} does not match the approved local test target.");
            }
        }
    }

    /** Reject unsafe process values before application/provider bootstrap. */
    private static function assertEnvironmentSafe(
        array $environment,
        bool $configurationCached,
        ?string $resolvedAppEnvironment = null,
    ): void {
        if ($configurationCached) {
            throw new RuntimeException('PostgreSQL race tests refuse cached Laravel configuration.');
        }

        $required = [
            'APP_ENV',
            'GOOGLE_CALENDAR_PG_RACE_TESTING',
            'GOOGLE_CALENDAR_PG_RACE_DATABASE',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_SSLMODE',
        ];

        foreach ($required as $name) {
            if (! array_key_exists($name, $environment) || $environment[$name] === null || $environment[$name] === '') {
                throw new RuntimeException("PostgreSQL race test requires an explicit {$name} value.");
            }
        }

        if (! array_key_exists('DB_PASSWORD', $environment) || $environment['DB_PASSWORD'] === null) {
            throw new RuntimeException('PostgreSQL race test requires DB_PASSWORD to be explicitly set (an empty value is allowed).');
        }

        if (($environment['DB_URL'] ?? '') !== '') {
            throw new RuntimeException('PostgreSQL race tests refuse a non-empty DB_URL.');
        }

        if ($environment['APP_ENV'] !== 'testing'
            || ($resolvedAppEnvironment !== null && $resolvedAppEnvironment !== 'testing')) {
            throw new RuntimeException('PostgreSQL race tests require APP_ENV=testing in both process and resolved configuration.');
        }

        if ($environment['GOOGLE_CALENDAR_PG_RACE_TESTING'] !== '1') {
            throw new RuntimeException('PostgreSQL race tests require GOOGLE_CALENDAR_PG_RACE_TESTING=1.');
        }

        if ($environment['DB_CONNECTION'] !== 'pgsql') {
            throw new RuntimeException('PostgreSQL race tests require DB_CONNECTION=pgsql.');
        }

        if ($environment['DB_HOST'] !== '127.0.0.1') {
            throw new RuntimeException('PostgreSQL race tests require DB_HOST=127.0.0.1.');
        }

        if ($environment['DB_PORT'] !== '5432') {
            throw new RuntimeException('PostgreSQL race tests require DB_PORT=5432.');
        }

        if ($environment['DB_DATABASE'] !== 'drive_phangan_gcal_test') {
            throw new RuntimeException('PostgreSQL race tests require DB_DATABASE=drive_phangan_gcal_test.');
        }

        if ($environment['GOOGLE_CALENDAR_PG_RACE_DATABASE'] !== 'drive_phangan_gcal_test'
            || $environment['GOOGLE_CALENDAR_PG_RACE_DATABASE'] !== $environment['DB_DATABASE']) {
            throw new RuntimeException('PostgreSQL race test database does not match GOOGLE_CALENDAR_PG_RACE_DATABASE.');
        }

        if ($environment['DB_USERNAME'] !== 'gcal_test') {
            throw new RuntimeException('PostgreSQL race tests require DB_USERNAME=gcal_test.');
        }

        if ($environment['DB_SSLMODE'] !== 'disable') {
            throw new RuntimeException('PostgreSQL race tests require DB_SSLMODE=disable.');
        }
    }

    /** Validate inputs, then resolve the uncached Laravel DB config with an initialized Application. */
    public static function assertProcessIsSafe(string $basePath): void
    {
        $environment = self::processEnvironment();
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        if (is_file($cachedConfigPath)) {
            throw new RuntimeException('PostgreSQL race tests refuse cached Laravel configuration.');
        }

        self::assertEnvironmentSafe($environment, false, 'testing');

        // database.php evaluates database_path() even for a connection whose DB is not selected.
        // Initialize Laravel first so those helpers resolve against an Application, not Container.
        $app = require $basePath.'/bootstrap/app.php';
        $app->useEnvironmentPath($basePath.'/tests/Support/no-environment-files');
        if ($app->configurationIsCached()) {
            throw new RuntimeException('PostgreSQL race tests refuse cached Laravel configuration.');
        }

        $databaseConfig = require $basePath.'/config/database.php';
        $appConfig = require $basePath.'/config/app.php';
        self::assertSafe($environment, $databaseConfig, false, (string) ($appConfig['env'] ?? ''));
    }

    /** @return array<string, string|null> */
    public static function processEnvironment(): array
    {
        $names = [
            'APP_ENV', 'GOOGLE_CALENDAR_PG_RACE_TESTING', 'GOOGLE_CALENDAR_PG_RACE_DATABASE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SSLMODE',
        ];
        $environment = [];

        foreach ($names as $name) {
            $values = [];
            $processValue = getenv($name);
            if ($processValue !== false) {
                $values[] = (string) $processValue;
            }
            if (array_key_exists($name, $_ENV)) {
                $values[] = (string) $_ENV[$name];
            }
            if (array_key_exists($name, $_SERVER)) {
                $values[] = (string) $_SERVER[$name];
            }

            $values = array_values(array_unique($values));
            if (count($values) > 1) {
                throw new RuntimeException("Conflicting process environment values were found for {$name}.");
            }

            $environment[$name] = $values[0] ?? null;
        }

        return $environment;
    }
}
