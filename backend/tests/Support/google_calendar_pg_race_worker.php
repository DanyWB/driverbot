<?php

declare(strict_types=1);

use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationResult;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOutboxClaim;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarReconciliationClaim;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxWorker;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationWorker;
use App\Models\Booking;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\GoogleCalendarPostgresTestGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$basePath = dirname(__DIR__, 2);

// A child process is an independent safety boundary. Validate before application/provider boot.
GoogleCalendarPostgresTestGuard::assertProcessIsSafe($basePath);

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__.'/no-environment-files');
if ($app->configurationIsCached()) {
    throw new RuntimeException('Google Calendar race worker refuses cached application configuration.');
}
$app->make(Kernel::class)->bootstrap();
GoogleCalendarPostgresTestGuard::assertSafe(
    GoogleCalendarPostgresTestGuard::processEnvironment(),
    $app['config']->get('database'),
    $app->configurationIsCached(),
    $app->environment(),
);

try {
    if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== getenv('DB_DATABASE')) {
        throw new RuntimeException('Worker database connection did not resolve to the verified PostgreSQL test database.');
    }
    DB::statement("SET lock_timeout = '5s'");
    DB::statement("SET statement_timeout = '10s'");
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (isset($input['barrier_key'])) {
        $key = (int) $input['barrier_key'];
        $slot = (int) $input['slot'];
        DB::select('SELECT pg_advisory_lock(CAST(? AS integer), CAST(? AS integer))', [$key, $slot]);
        $deadline = microtime(true) + 5;
        do {
            $ready = (int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = CAST(? AS oid) AND objid IN (1, 2) AND granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())", [$key]);
            if ($ready === 2) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Both PostgreSQL workers did not reach the real-connection barrier.');
            }
            usleep(10_000);
        } while (true);
        $wait = max(0, (int) (((float) $input['barrier_time'] - microtime(true)) * 1_000_000));
        if ($wait > 0) {
            usleep($wait);
        }
    }

    $output = match ($input['action']) {
        'claim_outbox' => claimOutbox($app->make(GoogleCalendarOutboxWorker::class)),
        'claim_reconciliation' => claimReconciliation($app->make(GoogleCalendarReconciliationWorker::class)),
        'request_reconciliation' => requestReconciliation($app->make(GoogleCalendarReconciliationService::class), $input),
        'claim_reconciliation_hold' => claimAndHoldReconciliation($app, $input),
        'process_outbox_hold' => processOutboxHold($app, $input),
        'process_reconciliation_hold' => processReconciliationHold($app, $input),
        default => throw new InvalidArgumentException('Unsupported Google Calendar race action.'),
    };
    echo json_encode($output, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}

function claimOutbox(GoogleCalendarOutboxWorker $worker): array
{
    $claim = $worker->claimNext();
    if ($claim === null) {
        return ['claimed' => false];
    }
    $row = DB::table('google_calendar_outbox')->where('id', $claim->outboxId)->first();
    return ['claimed' => true, 'id' => $claim->outboxId, 'token' => $claim->leaseToken, 'event_type' => $row->event_type];
}

function claimReconciliation(GoogleCalendarReconciliationWorker $worker): array
{
    $claim = $worker->claimNext();
    return $claim === null ? ['claimed' => false] : [
        'claimed' => true, 'id' => $claim->reconciliationId, 'generation' => $claim->generation, 'token' => $claim->leaseToken,
    ];
}

function requestReconciliation(GoogleCalendarReconciliationService $service, array $input): array
{
    $booking = Booking::query()->findOrFail($input['booking_id']);
    $row = $service->request($booking, GoogleCalendarEventType::from($input['event_type']));
    return ['id' => $row?->id, 'requested' => $row?->requested_generation, 'completed' => $row?->completed_generation];
}

function claimAndHoldReconciliation($app, array $input): array
{
    $worker = $app->make(GoogleCalendarReconciliationWorker::class);
    $claim = $worker->claimNext();
    if ($claim === null) {
        return ['claimed' => false];
    }
    $processor = new class(function (GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context) use ($input): GoogleCalendarOperationResult {
        touch($input['started']);
        awaitRelease($input['release']);
        return new GoogleCalendarOperationResult($request->googleEventId);
    }) implements GoogleCalendarOperationProcessor {
        public function __construct(private Closure $handler) {}
        public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
        {
            return ($this->handler)($request, $context);
        }
    };
    $app->instance(GoogleCalendarOperationProcessor::class, $processor);
    return ['claimed' => true, 'acknowledged' => $worker->processClaim($claim), 'generation' => $claim->generation];
}

function processOutboxHold($app, array $input): array
{
    $renewed = null;
    $processor = new class(function (GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context) use ($input, &$renewed): GoogleCalendarOperationResult {
        touch($input['started']);
        awaitRelease($input['release']);
        $renewed = $context->renewLease();
        return new GoogleCalendarOperationResult($request->googleEventId);
    }) implements GoogleCalendarOperationProcessor {
        public function __construct(private Closure $handler) {}
        public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
        {
            return ($this->handler)($request, $context);
        }
    };
    $app->instance(GoogleCalendarOperationProcessor::class, $processor);
    $claim = new GoogleCalendarOutboxClaim((int) $input['outbox_id'], (string) $input['token']);
    $acknowledged = $app->make(GoogleCalendarOutboxWorker::class)->processClaim($claim);
    return ['renewed' => $renewed, 'acknowledged' => $acknowledged];
}

function processReconciliationHold($app, array $input): array
{
    $processor = new class(function (GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context) use ($input): GoogleCalendarOperationResult {
        touch($input['started']);
        awaitRelease($input['release']);
        return new GoogleCalendarOperationResult($request->googleEventId);
    }) implements GoogleCalendarOperationProcessor {
        public function __construct(private Closure $handler) {}
        public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
        {
            return ($this->handler)($request, $context);
        }
    };
    $app->instance(GoogleCalendarOperationProcessor::class, $processor);
    $claim = new GoogleCalendarReconciliationClaim((int) $input['reconciliation_id'], (int) $input['booking_id'], 'pickup', (int) $input['generation'], (string) $input['token']);
    return ['acknowledged' => $app->make(GoogleCalendarReconciliationWorker::class)->processClaim($claim)];
}

function awaitRelease(string $path): void
{
    $deadline = microtime(true) + 8;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('Parent did not release the synchronized worker.');
    }
}
