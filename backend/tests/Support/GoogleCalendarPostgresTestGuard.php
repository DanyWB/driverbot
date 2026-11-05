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

        if (! in_array($environment['DB_HOST'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('PostgreSQL race tests require DB_HOST to be 127.0.0.1, localhost, or ::1.');
        }

        if (! ctype_digit($environment['DB_PORT']) || (int) $environment['DB_PORT'] < 1 || (int) $environment['DB_PORT'] > 65535) {
            throw new RuntimeException('PostgreSQL race tests require an explicit valid DB_PORT.');
        }

        $database = $environment['DB_DATABASE'];
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('PostgreSQL race test database name must end with the exact lowercase suffix _test.');
        }

        if ($environment['GOOGLE_CALENDAR_PG_RACE_DATABASE'] !== $database) {
            throw new RuntimeException('PostgreSQL race test database does not match GOOGLE_CALENDAR_PG_RACE_DATABASE.');
        }

        $connections = $databaseConfig['connections'] ?? null;
        $pgsql = is_array($connections) ? ($connections['pgsql'] ?? null) : null;
        if (($databaseConfig['default'] ?? null) !== 'pgsql' || ! is_array($pgsql) || ($pgsql['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Effective Laravel database configuration is not PostgreSQL.');
        }

        if (($pgsql['url'] ?? null) !== null && ($pgsql['url'] ?? '') !== '') {
            throw new RuntimeException('Effective Laravel PostgreSQL configuration contains a DB URL.');
        }

        if (($pgsql['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Effective Laravel PostgreSQL connection has an unexpected driver.');
        }

        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $configKey => $environmentKey) {
            $resolved = $pgsql[$configKey] ?? null;
            $expected = $environment[$environmentKey];
            if (! is_scalar($resolved) || (string) $resolved !== $expected) {
                throw new RuntimeException("Effective Laravel PostgreSQL {$configKey} does not match {$environmentKey}.");
            }
        }
    }

    /** Resolve the checked-in Laravel config files without booting the application or loading .env. */
    public static function assertProcessIsSafe(string $basePath): void
    {
        $environment = self::processEnvironment();
        $cachedConfigPath = $basePath.'/bootstrap/cache/config.php';
        if (is_file($cachedConfigPath)) {
            throw new RuntimeException('PostgreSQL race tests refuse cached Laravel configuration.');
        }

        // config/database.php only reads environment values; requiring it here verifies the same
        // effective values Laravel will use, while avoiding application/provider/database boot.
        $databaseConfig = require $basePath.'/config/database.php';
        $appConfig = require $basePath.'/config/app.php';
        self::assertSafe($environment, $databaseConfig, false, (string) ($appConfig['env'] ?? ''));
    }

    /** @return array<string, string|null> */
    public static function processEnvironment(): array
    {
        $names = [
            'APP_ENV', 'GOOGLE_CALENDAR_PG_RACE_TESTING', 'GOOGLE_CALENDAR_PG_RACE_DATABASE',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL',
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
