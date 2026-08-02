<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Data\ReleaseCheck;
use App\Domain\Pricing\Services\PricingCatalogAuditor;
use App\Models\NotificationOutbox;
use App\Models\ServiceApiClient;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReleasePreflightService
{
    public function __construct(private readonly PricingCatalogAuditor $pricing) {}

    /** @return list<ReleaseCheck> */
    public function inspect(bool $strict): array
    {
        $checks = [
            $this->requirement('app.environment', app()->isProduction(), $strict, 'APP_ENV is production.', 'APP_ENV must be production.'),
            $this->requirement('app.debug', ! config('app.debug'), $strict, 'Debug mode is disabled.', 'APP_DEBUG must be false.'),
            $this->requirement('app.key', $this->validAppKey(), true, 'APP_KEY is configured.', 'APP_KEY is missing or invalid.'),
            $this->requirement('app.url', $this->validHttpsUrl(), $strict, 'APP_URL uses HTTPS.', 'APP_URL must be a valid HTTPS URL.'),
            $this->requirement('database.driver', DB::getDriverName() === 'pgsql', $strict, 'PostgreSQL is configured.', 'DB_CONNECTION must be pgsql.'),
            $this->databaseConfiguration($strict),
            $this->requirement('cache.driver', config('cache.default') === 'redis', $strict, 'Redis cache is configured.', 'CACHE_STORE must be redis.'),
            $this->requirement('queue.driver', config('queue.default') === 'redis', $strict, 'Redis queue is configured.', 'QUEUE_CONNECTION must be redis.'),
            $this->requirement('session.driver', config('session.driver') === 'redis', $strict, 'Redis sessions are configured.', 'SESSION_DRIVER must be redis.'),
            $this->requirement('session.encryption', (bool) config('session.encrypt'), $strict, 'Session payload encryption is enabled.', 'SESSION_ENCRYPT must be true.'),
            $this->requirement('session.secure_cookie', (bool) config('session.secure'), $strict, 'Session cookies require HTTPS.', 'SESSION_SECURE_COOKIE must be true.'),
            $this->requirement('security.csp', (bool) config('security.csp.enabled'), $strict, 'Content Security Policy is enabled.', 'SECURITY_CSP_ENABLED must be true.'),
            $this->requirement('security.hsts', (bool) config('security.hsts.enabled'), $strict, 'HSTS is enabled.', 'SECURITY_HSTS_ENABLED must be true.'),
            $this->mailConfiguration($strict),
            $this->runtimeExtensions(),
            $this->telegramConfiguration($strict),
            $this->managerConfiguration($strict),
            $this->storageDirectories(),
            $this->storageLink($strict),
            $this->frontendBuild($strict),
        ];

        return [...$checks, ...$this->databaseChecks($strict)];
    }

    private function validAppKey(): bool
    {
        $key = trim((string) config('app.key'));

        return strlen($key) >= 32 && ! str_contains(strtolower($key), 'change_me');
    }

    private function validHttpsUrl(): bool
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        $normalizedHost = is_string($host) ? strtolower($host) : null;

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($normalizedHost)
            && $normalizedHost !== 'example.com'
            && ! str_ends_with($normalizedHost, '.example.com');
    }

    private function databaseConfiguration(bool $strict): ReleaseCheck
    {
        $connection = config('database.default');
        $database = trim((string) config("database.connections.{$connection}.database"));
        $username = trim((string) config("database.connections.{$connection}.username"));
        $password = trim((string) config("database.connections.{$connection}.password"));
        $valid = $database !== '' && $username !== '' && $password !== ''
            && ! $this->isPlaceholder($database)
            && ! $this->isPlaceholder($username)
            && ! $this->isPlaceholder($password);

        return $this->requirement(
            'database.configuration',
            $valid,
            $strict,
            'Database name, user and password are configured.',
            'Database credentials are missing or still contain placeholders.',
        );
    }

    private function mailConfiguration(bool $strict): ReleaseCheck
    {
        $mailer = (string) config('mail.default');
        $host = trim((string) config("mail.mailers.{$mailer}.host"));
        $username = trim((string) config("mail.mailers.{$mailer}.username"));
        $password = trim((string) config("mail.mailers.{$mailer}.password"));
        $from = trim((string) config('mail.from.address'));
        $valid = ! in_array($mailer, ['array', 'log'], true)
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && ! $this->isPlaceholder($from);

        if ($mailer === 'smtp') {
            $valid = $valid
                && $host !== ''
                && $username !== ''
                && $password !== ''
                && ! $this->isPlaceholder($host)
                && ! $this->isPlaceholder($username)
                && ! $this->isPlaceholder($password);
        }

        return $this->requirement(
            'mail.transport',
            $valid,
            $strict,
            'A delivery mail transport is configured.',
            'Configure a real mail transport for administrator password resets.',
        );
    }

    private function runtimeExtensions(): ReleaseCheck
    {
        $required = ['curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'redis'];
        $missing = array_values(array_filter($required, fn (string $extension): bool => ! extension_loaded($extension)));

        return $missing === []
            ? $this->pass('runtime.extensions', 'Required PHP extensions are loaded.')
            : $this->fail('runtime.extensions', 'Missing PHP extensions: '.implode(', ', $missing).'.');
    }

    private function telegramConfiguration(bool $strict): ReleaseCheck
    {
        $token = trim((string) config('notifications.telegram.bot_token'));
        $chatId = trim((string) config('notifications.telegram.admin_chat_id'));
        $valid = preg_match('/^[0-9]{6,12}:[A-Za-z0-9_-]{30,}$/', $token) === 1
            && preg_match('/^-?[1-9][0-9]{4,19}$/', $chatId) === 1;

        return $this->requirement(
            'telegram.configuration',
            $valid,
            $strict,
            'Telegram token and numeric admin chat ID are configured.',
            'TELEGRAM_BOT_TOKEN or TELEGRAM_ADMIN_CHAT_ID is missing or malformed.',
        );
    }

    private function managerConfiguration(bool $strict): ReleaseCheck
    {
        $manager = trim((string) config('business.manager_telegram'));
        $terms = trim((string) config('business.terms_version'));
        $valid = preg_match('/^@[A-Za-z0-9_]{5,32}$/', $manager) === 1
            && ! $this->isPlaceholder($manager)
            && $terms !== ''
            && ! $this->isPlaceholder($terms);

        return $this->requirement(
            'business.runtime',
            $valid,
            $strict,
            'Manager contact and booking terms version are configured.',
            'MANAGER_TELEGRAM_USERNAME or BOOKING_TERMS_VERSION is invalid.',
        );
    }

    private function storageDirectories(): ReleaseCheck
    {
        $paths = [
            storage_path('app/private'),
            storage_path('app/public'),
            storage_path('framework/cache'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];
        $invalid = array_values(array_filter(
            $paths,
            fn (string $path): bool => ! is_dir($path) || ! is_writable($path),
        ));

        return $invalid === []
            ? $this->pass('storage.writable', 'Persistent storage directories are writable.')
            : $this->fail('storage.writable', 'Missing or non-writable storage directories: '.implode(', ', $invalid).'.');
    }

    private function storageLink(bool $strict): ReleaseCheck
    {
        $link = public_path('storage');
        $exists = is_link($link)
            || is_dir($link)
            || (PHP_OS_FAMILY === 'Windows' && file_exists($link));

        return $this->requirement(
            'storage.public_link',
            $exists,
            $strict,
            'Public storage link exists.',
            'Run php artisan storage:link.',
        );
    }

    private function frontendBuild(bool $strict): ReleaseCheck
    {
        return $this->requirement(
            'frontend.build',
            is_file(public_path('build/manifest.json')),
            $strict,
            'Frontend production manifest exists.',
            'Frontend production build is missing.',
        );
    }

    /** @return list<ReleaseCheck> */
    private function databaseChecks(bool $strict): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            return [$this->fail('database.connection', 'Database connection failed: '.$exception->getMessage())];
        }

        $checks = [$this->pass('database.connection', 'Database connection is available.')];

        try {
            Redis::connection()->ping();
            $checks[] = $this->pass('redis.connection', 'Redis connection is available.');
        } catch (Throwable $exception) {
            $checks[] = $this->fail('redis.connection', 'Redis connection failed: '.$exception->getMessage());
        }

        $checks[] = $this->pendingMigrations();
        $checks[] = $this->adminAccounts($strict);
        $checks[] = $this->serviceClient($strict);
        $checks[] = $this->catalog($strict);
        $checks[] = $this->vehiclePhotos($strict);
        $checks[] = $this->pricingCatalog();
        $checks[] = $this->failedJobs();
        $checks[] = $this->failedNotifications();

        return $checks;
    }

    private function pendingMigrations(): ReleaseCheck
    {
        try {
            $migrator = app(Migrator::class);

            if (! $migrator->repositoryExists()) {
                return $this->fail('database.migrations', 'Migration repository does not exist.');
            }

            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

            return $pending === []
                ? $this->pass('database.migrations', 'All migrations are applied.')
                : $this->fail('database.migrations', 'Pending migrations: '.implode(', ', $pending).'.');
        } catch (Throwable $exception) {
            return $this->fail('database.migrations', 'Migration check failed: '.$exception->getMessage());
        }
    }

    private function adminAccounts(bool $strict): ReleaseCheck
    {
        $active = User::query()->where('is_active', true)->count();
        $withoutTwoFactor = User::query()
            ->where('is_active', true)
            ->whereNull('two_factor_confirmed_at')
            ->count();

        if ($active === 0) {
            return $this->requirement('admin.accounts', false, $strict, '', 'No active administrator exists.');
        }

        return $withoutTwoFactor === 0
            ? $this->pass('admin.accounts', "Active administrators: {$active}; two-factor authentication is enabled.")
            : $this->warn('admin.accounts', "Active administrators: {$active}; {$withoutTwoFactor} still require two-factor setup.");
    }

    private function serviceClient(bool $strict): ReleaseCheck
    {
        $required = ['bot:read', 'bot:write', 'bot:documents'];
        $exists = ServiceApiClient::query()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->get()
            ->contains(fn (ServiceApiClient $client): bool => collect($required)->every(
                fn (string $ability): bool => $client->allows($ability),
            ));

        return $this->requirement(
            'bot.service_client',
            $exists,
            $strict,
            'An active Bot API client has all required abilities.',
            'Issue an active Bot API client with read, write and document abilities.',
        );
    }

    private function catalog(bool $strict): ReleaseCheck
    {
        $visible = Vehicle::query()
            ->where('is_active', true)
            ->where('is_visible_for_booking', true)
            ->count();

        return $this->requirement(
            'catalog.visible_vehicles',
            $visible > 0,
            $strict,
            "Visible active vehicles: {$visible}.",
            'No active vehicle is visible for booking.',
        );
    }

    private function vehiclePhotos(bool $strict): ReleaseCheck
    {
        $missing = Vehicle::query()
            ->where('is_active', true)
            ->where('is_visible_for_booking', true)
            ->whereDoesntHave('photos', fn ($query) => $query->where('is_primary', true))
            ->count();

        return $this->requirement(
            'catalog.primary_photos',
            $missing === 0,
            $strict,
            'Every visible vehicle has a primary photo.',
            "Visible vehicles without a primary photo: {$missing}.",
        );
    }

    private function pricingCatalog(): ReleaseCheck
    {
        try {
            $audit = $this->pricing->audit();

            return $audit->passed()
                ? $this->pass('pricing.catalog', "Pricing audit passed for {$audit->visibleVehicles} visible vehicles.")
                : $this->fail('pricing.catalog', implode(' ', $audit->errors));
        } catch (Throwable $exception) {
            return $this->fail('pricing.catalog', 'Pricing audit failed: '.$exception->getMessage());
        }
    }

    private function failedJobs(): ReleaseCheck
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->fail('queue.failed_jobs', 'failed_jobs table is missing.');
        }

        $count = DB::table('failed_jobs')->count();

        return $count === 0
            ? $this->pass('queue.failed_jobs', 'No failed queue jobs exist.')
            : $this->fail('queue.failed_jobs', "Failed queue jobs: {$count}.");
    }

    private function failedNotifications(): ReleaseCheck
    {
        $count = NotificationOutbox::query()
            ->where('status', NotificationOutbox::STATUS_FAILED)
            ->count();

        return $count === 0
            ? $this->pass('notifications.failed', 'No failed notifications exist.')
            : $this->fail('notifications.failed', "Failed notification records: {$count}.");
    }

    private function requirement(
        string $id,
        bool $condition,
        bool $strict,
        string $success,
        string $failure,
    ): ReleaseCheck {
        if ($condition) {
            return $this->pass($id, $success);
        }

        return $strict ? $this->fail($id, $failure) : $this->warn($id, $failure);
    }

    private function isPlaceholder(string $value): bool
    {
        return str_contains(strtoupper($value), 'CHANGE_ME');
    }

    private function pass(string $id, string $message): ReleaseCheck
    {
        return new ReleaseCheck($id, ReleaseCheck::PASS, $message);
    }

    private function warn(string $id, string $message): ReleaseCheck
    {
        return new ReleaseCheck($id, ReleaseCheck::WARNING, $message);
    }

    private function fail(string $id, string $message): ReleaseCheck
    {
        return new ReleaseCheck($id, ReleaseCheck::FAILURE, $message);
    }
}
