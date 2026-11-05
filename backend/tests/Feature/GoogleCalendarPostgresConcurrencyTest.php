<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxWorker;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxService;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationWorker;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use App\Models\GoogleCalendarReconciliation;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Support\GoogleCalendarPostgresTestGuard;

/** Real process-level claim/trigger schedules for the release PostgreSQL engine. */
class GoogleCalendarPostgresConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $bookingIds = [];

    /** @var list<int> */
    private array $customerIds = [];

    /** @var list<int> */
    private array $vehicleIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    private User $admin;

    private BookingService $bookingService;

    protected function setUp(): void
    {
        GoogleCalendarPostgresTestGuard::assertProcessIsSafe(dirname(__DIR__, 2));
        parent::setUp();

        config()->set('google_calendar.calendars.pickup', 'synthetic-pickup-calendar');
        config()->set('google_calendar.calendars.return', 'synthetic-return-calendar');
        $this->seed();
        $this->admin = User::factory()->create();
        $this->adminIds[] = (int) $this->admin->id;
        $this->bookingService = app(BookingService::class);
    }

    /**
     * Laravel's TestCase creates the application before RefreshDatabase runs its migration hook.
     * Check raw/effective file config before boot, then check the booted config before returning
     * the application to Laravel's trait setup.
     */
    protected function createApplication()
    {
        $basePath = dirname(__DIR__, 2);
        GoogleCalendarPostgresTestGuard::assertProcessIsSafe($basePath);

        $app = require $basePath.'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/../Support/no-environment-files');
        if ($app->configurationIsCached()) {
            throw new RuntimeException('PostgreSQL race tests refuse cached Laravel configuration.');
        }

        $app->make(Kernel::class)->bootstrap();
        GoogleCalendarPostgresTestGuard::assertSafe(
            GoogleCalendarPostgresTestGuard::processEnvironment(),
            $app['config']->get('database'),
            $app->configurationIsCached(),
            $app->environment(),
        );

        return $app;
    }

    /** RefreshDatabase needs its schema, but subprocess tests require committed fixtures. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->bookingIds !== []) {
                DB::table('google_calendar_reconciliations')->whereIn('booking_id', $this->bookingIds)->delete();
                DB::table('google_calendar_outbox')->whereIn('booking_id', $this->bookingIds)->delete();
                DB::table('google_calendar_events')->whereIn('booking_id', $this->bookingIds)->delete();
                DB::table('bookings')->whereIn('id', $this->bookingIds)->delete();
            }
            if ($this->vehicleIds !== []) {
                VehiclePriceTier::query()->whereIn('vehicle_id', $this->vehicleIds)->delete();
                DB::table('vehicle_occupancies')->whereIn('vehicle_id', $this->vehicleIds)->delete();
                Vehicle::query()->whereIn('id', $this->vehicleIds)->delete();
            }
            if ($this->customerIds !== []) {
                Customer::query()->whereIn('id', $this->customerIds)->delete();
            }
            if ($this->adminIds !== []) {
                DB::table('audit_logs')->whereIn('actor_admin_id', $this->adminIds)->delete();
                User::query()->whereIn('id', $this->adminIds)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_processes_cannot_claim_one_reconciliation_generation(): void
    {
        $booking = $this->booking();
        app(GoogleCalendarReconciliationService::class)->request($booking, GoogleCalendarEventType::Pickup);

        $results = $this->race(
            ['action' => 'claim_reconciliation', 'booking_id' => $booking->id],
            ['action' => 'claim_reconciliation', 'booking_id' => $booking->id],
        );

        $claims = array_values(array_filter($results, fn (array $result): bool => $result['claimed']));
        $this->assertCount(1, $claims);
        $this->assertSame(1, (int) GoogleCalendarReconciliation::query()->where('booking_id', $booking->id)->value('claimed_generation'));
        $this->assertSame(1, GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->whereNotNull('sync_lease_token')->count());
    }

    public function test_two_processes_cannot_claim_one_outbox_operation(): void
    {
        $booking = $this->booking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')->update(['available_at' => now()->addHour()]);
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')->update(['available_at' => now()->addHour()]);

        $results = $this->race(
            ['action' => 'claim_outbox', 'booking_id' => $booking->id],
            ['action' => 'claim_outbox', 'booking_id' => $booking->id],
        );

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['claimed']));
        $this->assertSame(1, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->where('status', GoogleCalendarOutbox::STATUS_PROCESSING)->count());
    }

    public function test_concurrent_reconciliation_requests_coalesce_without_losing_pending_work(): void
    {
        $booking = $this->booking();
        $beforeOutbox = GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count();

        $this->race(
            ['action' => 'request_reconciliation', 'booking_id' => $booking->id, 'event_type' => 'pickup'],
            ['action' => 'request_reconciliation', 'booking_id' => $booking->id, 'event_type' => 'pickup'],
        );

        $row = GoogleCalendarReconciliation::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(1, (int) $row->requested_generation);
        $this->assertSame(0, (int) $row->completed_generation);
        $this->assertSame(1, GoogleCalendarReconciliation::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());
        $this->assertSame($beforeOutbox, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    public function test_trigger_during_active_processing_records_one_followup_generation(): void
    {
        $booking = $this->booking();
        app(GoogleCalendarReconciliationService::class)->request($booking, GoogleCalendarEventType::Pickup);
        $paths = $this->paths();
        $process = $this->worker(['action' => 'claim_reconciliation_hold', 'booking_id' => $booking->id, ...$paths]);
        try {
            $process->start();
            $this->waitForFile($paths['started']);
            app(GoogleCalendarReconciliationService::class)->request($booking, GoogleCalendarEventType::Pickup);
            $row = GoogleCalendarReconciliation::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
            $this->assertSame(1, (int) $row->claimed_generation);
            $this->assertSame(2, (int) $row->requested_generation);
            touch($paths['release']);
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            touch($paths['release']);
            if ($process->isRunning()) {
                $process->stop(0);
            }
            $this->removePaths($paths);
        }

        $row->refresh();
        $this->assertSame(1, (int) $row->completed_generation);
        $this->assertGreaterThan((int) $row->completed_generation, (int) $row->requested_generation);
    }

    public function test_pickup_and_return_claim_independently_under_concurrent_workers(): void
    {
        $booking = $this->booking();
        $results = $this->race(
            ['action' => 'claim_outbox', 'booking_id' => $booking->id],
            ['action' => 'claim_outbox', 'booking_id' => $booking->id],
        );

        $claims = array_values(array_filter($results, fn (array $result): bool => $result['claimed']));
        $this->assertCount(2, $claims);
        $types = array_map(fn (array $result): string => $result['event_type'], $claims);
        sort($types);
        $this->assertSame(['pickup', 'return'], $types);
        $this->assertSame(2, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('status', GoogleCalendarOutbox::STATUS_PROCESSING)->count());
    }

    public function test_expired_outbox_worker_cannot_renew_or_ack_after_recovery_reclaims_mapping(): void
    {
        $booking = $this->booking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')->update(['available_at' => now()->addHour()]);
        $claim = app(GoogleCalendarOutboxWorker::class)->claimNext();
        $this->assertNotNull($claim);
        $paths = $this->paths();
        $oldWorker = $this->worker(['action' => 'process_outbox_hold', 'booking_id' => $booking->id,
            'outbox_id' => $claim->outboxId, 'token' => $claim->leaseToken, ...$paths]);
        try {
            $oldWorker->start();
            $this->waitForFile($paths['started']);
            Booking::query()->whereKey($booking->id)->update(['starts_on' => '2026-08-11']);
            app(GoogleCalendarOutboxService::class)->upsert(
                $booking->fresh(), GoogleCalendarEventType::Pickup, 'synthetic-pickup-calendar', 'concurrent-newer-revision',
            );
            GoogleCalendarOutbox::query()->whereKey($claim->outboxId)->update(['locked_until' => now()->subSecond()]);
            GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->update(['sync_locked_until' => now()->subSecond()]);
            app(GoogleCalendarOutboxWorker::class)->processBatch(1);
            touch($paths['release']);
            $oldWorker->wait();
            $this->assertTrue($oldWorker->isSuccessful(), $oldWorker->getErrorOutput());
            $result = json_decode(trim($oldWorker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            touch($paths['release']);
            if ($oldWorker->isRunning()) {
                $oldWorker->stop(0);
            }
            $this->removePaths($paths);
        }

        $this->assertFalse($result['renewed']);
        $this->assertFalse($result['acknowledged']);
        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(2, (int) $mapping->last_requested_revision);
        $this->assertSame(2, (int) $mapping->last_applied_revision);
        $this->assertNotSame($claim->leaseToken, GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->value('sync_lease_token'));
    }

    public function test_cancellation_during_reconciliation_processing_keeps_tombstone_authoritative(): void
    {
        $booking = $this->booking();
        app(GoogleCalendarReconciliationService::class)->request($booking, GoogleCalendarEventType::Pickup);
        $claim = app(GoogleCalendarReconciliationWorker::class)->claimNext();
        $this->assertNotNull($claim);
        $paths = $this->paths();
        $oldWorker = $this->worker(['action' => 'process_reconciliation_hold', 'booking_id' => $booking->id,
            'reconciliation_id' => $claim->reconciliationId, 'generation' => $claim->generation, 'token' => $claim->leaseToken, ...$paths]);
        try {
            $oldWorker->start();
            $this->waitForFile($paths['started']);
            $this->bookingService->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'PostgreSQL concurrency test');
            touch($paths['release']);
            $oldWorker->wait();
            $this->assertTrue($oldWorker->isSuccessful(), $oldWorker->getErrorOutput());
        } finally {
            touch($paths['release']);
            if ($oldWorker->isRunning()) {
                $oldWorker->stop(0);
            }
            $this->removePaths($paths);
        }

        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame('delete_pending', $mapping->sync_status);
        $this->assertSame(2, (int) $mapping->last_requested_revision);
        app(GoogleCalendarOutboxWorker::class)->processBatch(2);
        $this->assertSame('deleted', $mapping->fresh()->sync_status);
        $this->assertSame(1, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->where('operation', 'delete')->count());
    }

    private function booking(): Booking
    {
        $customer = Customer::factory()->create(['name' => 'PostgreSQL race customer']);
        $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => false]);
        $this->customerIds[] = (int) $customer->id;
        $this->vehicleIds[] = (int) $vehicle->id;
        $season = PricingSeason::query()->where('key', PricingSeasonKey::Low->value)->firstOrFail();
        VehiclePriceTier::query()->create([
            'vehicle_id' => $vehicle->id, 'pricing_season_id' => $season->id, 'tier_key' => PricingTier::OneDay,
            'min_days' => 1, 'max_days' => 6, 'anchor_days' => 1, 'package_total' => 300,
            'daily_rate' => '300.000000', 'currency' => 'THB', 'is_active' => true,
        ]);
        $booking = $this->bookingService->create(new CreateBookingData(
            customerId: (int) $customer->id,
            vehicleId: (int) $vehicle->id,
            startsOn: '2026-08-10',
            endsOn: '2026-08-12',
            source: BookingSource::AdminManual,
            initialStatus: BookingStatus::Pending,
            pickupTime: '10:00',
            returnTime: '12:00',
        ), BookingActor::admin((int) $this->admin->id));
        $this->bookingIds[] = (int) $booking->id;

        return $booking;
    }

    /** @param array<string, mixed> $first @param array<string, mixed> $second @return list<array<string, mixed>> */
    private function race(array $first, array $second): array
    {
        $barrierKey = random_int(10_000, 2_000_000_000);
        $barrier = microtime(true) + 0.5;
        $workers = [
            $this->worker([...$first, 'barrier_key' => $barrierKey, 'barrier_time' => $barrier, 'slot' => 1]),
            $this->worker([...$second, 'barrier_key' => $barrierKey, 'barrier_time' => $barrier, 'slot' => 2]),
        ];
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $verifiedEnvironment = GoogleCalendarPostgresTestGuard::processEnvironment();
        $environment = [
            'APP_ENV' => $verifiedEnvironment['APP_ENV'], 'APP_KEY' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'DB_CONNECTION' => $verifiedEnvironment['DB_CONNECTION'], 'DB_URL' => '',
            'DB_HOST' => $verifiedEnvironment['DB_HOST'], 'DB_PORT' => $verifiedEnvironment['DB_PORT'],
            'DB_DATABASE' => $verifiedEnvironment['DB_DATABASE'], 'DB_USERNAME' => $verifiedEnvironment['DB_USERNAME'],
            'DB_PASSWORD' => $verifiedEnvironment['DB_PASSWORD'], 'DB_SEARCH_PATH' => (string) $connection['search_path'],
            'DB_SSLMODE' => (string) $connection['sslmode'], 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array', 'GOOGLE_CALENDAR_PG_RACE_TESTING' => $verifiedEnvironment['GOOGLE_CALENDAR_PG_RACE_TESTING'],
            'GOOGLE_CALENDAR_PG_RACE_DATABASE' => $verifiedEnvironment['GOOGLE_CALENDAR_PG_RACE_DATABASE'],
        ];

        return new Process([PHP_BINARY, base_path('tests/Support/google_calendar_pg_race_worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 20);
    }

    /** @return array{started: string, release: string} */
    private function paths(): array
    {
        $id = bin2hex(random_bytes(12));
        return ['started' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'gcal-'.$id.'.started', 'release' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'gcal-'.$id.'.release'];
    }

    /** @param array{started: string, release: string} $paths */
    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFileExists($path, 'Concurrent worker did not reach its remote-processing barrier.');
    }

    /** @param array{started: string, release: string} $paths */
    private function removePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
