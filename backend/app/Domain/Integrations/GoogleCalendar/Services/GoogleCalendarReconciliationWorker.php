<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarReconciliationClaim;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Models\Booking;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarReconciliation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Processes persistent desired-state reconciliation records; pickup and return lease independently. */
class GoogleCalendarReconciliationWorker
{
    private const DELETABLE = [
        BookingStatus::Cancelled->value,
        BookingStatus::CancelledByClient->value,
        BookingStatus::CancelledUnavailable->value,
        BookingStatus::Expired->value,
    ];

    public function __construct(
        private readonly GoogleCalendarOperationProcessor $processor,
        private readonly GoogleCalendarEventPayloadBuilder $payloadBuilder,
        private readonly GoogleCalendarEventIdentity $identity,
        private readonly GoogleCalendarReconciliationService $reconciliations,
        private readonly NotificationOutboxService $notifications,
    ) {}

    public function claimNext(): ?GoogleCalendarReconciliationClaim
    {
        $driver = DB::getDriverName();
        return DB::transaction(function () use ($driver): ?GoogleCalendarReconciliationClaim {
            $now = now();
            $query = GoogleCalendarReconciliation::query()
                ->whereColumn('requested_generation', '>', 'completed_generation')
                ->where('available_at', '<=', $now)
                ->where(function (Builder $lease): void {
                    $lease->whereNull('lease_token')->orWhereNull('locked_until')->orWhere('locked_until', '<=', now());
                })
                ->whereNotExists(function ($subquery) use ($now): void {
                    $subquery->selectRaw('1')->from('google_calendar_events as active_mapping_lease')
                        ->whereColumn('active_mapping_lease.booking_id', 'google_calendar_reconciliations.booking_id')
                        ->whereColumn('active_mapping_lease.event_type', 'google_calendar_reconciliations.event_type')
                        ->whereNotNull('active_mapping_lease.sync_lease_token')
                        ->where('active_mapping_lease.sync_locked_until', '>', $now);
                })->orderBy('id');
            // Candidate read is optimistic; the booking lock below serializes claims and each
            // eligibility predicate is rechecked after locking the mapping and reconciliation row.
            $candidate = $query->first(['id', 'booking_id', 'event_type']);
            if (! $candidate instanceof GoogleCalendarReconciliation) {
                return null;
            }

            // Lock in the booking -> mapping -> reconciliation order used by lifecycle writes.
            $booking = Booking::query()->lockForUpdate()->find($candidate->booking_id);
            if (! $booking instanceof Booking) {
                return null;
            }

            $mappingQuery = GoogleCalendarEvent::query()->where('booking_id', $booking->getKey())->where('event_type', $candidate->event_type);
            if ($driver !== 'sqlite') {
                $mappingQuery->lockForUpdate();
            }
            $mapping = $mappingQuery->first();
            if (! $mapping instanceof GoogleCalendarEvent
                || ($mapping->sync_lease_token !== null && $mapping->sync_locked_until?->isFuture())) {
                return null;
            }

            $row = GoogleCalendarReconciliation::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $row instanceof GoogleCalendarReconciliation
                || (int) $row->requested_generation <= (int) $row->completed_generation
                || $row->available_at->isFuture()
                || ($row->lease_token !== null && $row->locked_until?->isFuture())) {
                return null;
            }

            // An expired lease may have sent a remote request and then died before
            // acknowledging it. Preserve that uncertainty across every later repair.
            $expiredPreviousLease = $row->lease_token !== null
                && ($row->locked_until === null || ! $row->locked_until->isFuture());
            if ($expiredPreviousLease) {
                if ($row->uncertain_since === null) {
                    $row->uncertain_since = now();
                }
                if ($row->last_error === null) {
                    $row->last_error = 'A reconciliation lease expired; remote outcome may be unknown.';
                }
            }

            $token = (string) Str::uuid();
            $until = now()->addSeconds(max(15, (int) config('google_calendar.outbox.lease_seconds', 120)));
            $generation = (int) $row->requested_generation;
            $row->forceFill([
                'claimed_generation' => $generation,
                'lease_token' => $token,
                'locked_until' => $until,
            ])->save();
            $claimed = GoogleCalendarEvent::query()->whereKey($mapping->id)
                ->where(function (Builder $lease): void {
                    $lease->whereNull('sync_lease_token')->orWhereNull('sync_locked_until')->orWhere('sync_locked_until', '<=', now());
                })->update(['sync_lease_token' => $token, 'sync_locked_until' => $until, 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw new \RuntimeException('Calendar mapping lease was acquired concurrently.');
            }

            return new GoogleCalendarReconciliationClaim((int) $row->id, (int) $row->booking_id, (string) $row->event_type, $generation, $token);
        }, 3);
    }

    /** @return array{claimed: int, processed: int, failed: int} */
    public function processBatch(?int $limit = null): array
    {
        $limit = min(1000, max(1, $limit ?? (int) config('google_calendar.outbox.batch_size', 50)));
        $counts = ['claimed' => 0, 'processed' => 0, 'failed' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $claim = $this->claimNext();
            if (! $claim instanceof GoogleCalendarReconciliationClaim) {
                break;
            }
            $counts['claimed']++;
            $counts[$this->processClaim($claim) ? 'processed' : 'failed']++;
        }
        return $counts;
    }

    public function processClaim(GoogleCalendarReconciliationClaim $claim): bool
    {
        $request = DB::transaction(function () use ($claim): GoogleCalendarOperationRequest|string|null {
            $booking = Booking::query()->with(['customer', 'vehicle'])->lockForUpdate()->find($claim->bookingId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $claim->bookingId)->where('event_type', $claim->eventType)->lockForUpdate()->first();
            $row = GoogleCalendarReconciliation::query()->lockForUpdate()->find($claim->reconciliationId);
            if (! $this->owns($row, $mapping, $claim)) {
                return null;
            }
            if (! $booking instanceof Booking) {
                return 'Booking no longer exists.';
            }
            $status = $booking->bookingStatus();
            if ($status === BookingStatus::Process) {
                return 'no-op';
            }
            $delete = in_array($status->value, self::DELETABLE, true);
            if (! $delete && in_array($mapping->sync_status, [GoogleCalendarSyncStatus::DeletePending->value, GoogleCalendarSyncStatus::Deleted->value], true)) {
                return 'no-op';
            }
            $eventType = GoogleCalendarEventType::from($claim->eventType);
            if (! $mapping->google_event_id) {
                $mapping->google_event_id = $this->identity->for($booking, $eventType);
            }
            if ($delete && $mapping->sync_status !== GoogleCalendarSyncStatus::Deleted->value) {
                $mapping->sync_status = GoogleCalendarSyncStatus::DeletePending->value;
            }
            $mapping->save();
            $operation = $delete ? GoogleCalendarOperation::Delete : GoogleCalendarOperation::Upsert;
            return new GoogleCalendarOperationRequest(
                (string) $booking->public_id,
                $eventType,
                $operation,
                (string) $mapping->calendar_id,
                (string) $mapping->google_event_id,
                (int) $mapping->last_requested_revision,
                $delete ? null : $this->payloadBuilder->build($booking, $eventType),
            );
        }, 3);

        if ($request === 'no-op') {
            return $this->completeWithoutRemoteOperation($claim);
        }
        if (is_string($request)) {
            return $this->fail($claim, $request, false);
        }
        if (! $request instanceof GoogleCalendarOperationRequest) {
            return false;
        }

        $leaseSeconds = max(15, (int) config('google_calendar.outbox.lease_seconds', 120));
        $timeoutSeconds = max(1, min((int) config('google_calendar.outbox.processor_timeout_seconds', 45), $leaseSeconds - 10));
        $context = new GoogleCalendarOperationContext($timeoutSeconds, fn (): bool => $this->renew($claim, $leaseSeconds));
        try {
            if (! $context->renewLease()) {
                return false;
            }
            $result = $this->processor->process($request, $context);
            if ($result->googleEventId !== null && ! hash_equals($request->googleEventId, $result->googleEventId)) {
                return $this->fail($claim, 'Processor returned an unexpected event ID.', false, $result->googleEventId);
            }
            return $this->complete($claim, $request, $result->remoteStateVerified);
        } catch (GoogleCalendarProcessingException $exception) {
            return $this->fail($claim, $exception->getMessage(), $exception->retryable, null, $exception->remoteOutcomeUnknown);
        } catch (Throwable $exception) {
            report($exception);
            return $this->fail($claim, 'Unclassified reconciliation failure: '.class_basename($exception), true, null, true);
        }
    }

    private function renew(GoogleCalendarReconciliationClaim $claim, int $leaseSeconds): bool
    {
        return DB::transaction(function () use ($claim, $leaseSeconds): bool {
            Booking::query()->lockForUpdate()->find($claim->bookingId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $claim->bookingId)->where('event_type', $claim->eventType)->lockForUpdate()->first();
            $row = GoogleCalendarReconciliation::query()->lockForUpdate()->find($claim->reconciliationId);
            if (! $this->owns($row, $mapping, $claim)) {
                return false;
            }
            $until = now()->addSeconds($leaseSeconds);
            $row->locked_until = $until;
            $mapping->sync_locked_until = $until;
            $row->save();
            $mapping->save();
            return true;
        }, 3);
    }

    private function complete(GoogleCalendarReconciliationClaim $claim, GoogleCalendarOperationRequest $request, bool $remoteStateVerified): bool
    {
        $ok = DB::transaction(function () use ($claim, $request, $remoteStateVerified): bool {
            $booking = Booking::query()->lockForUpdate()->find($claim->bookingId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $claim->bookingId)->where('event_type', $claim->eventType)->lockForUpdate()->first();
            $row = GoogleCalendarReconciliation::query()->lockForUpdate()->find($claim->reconciliationId);
            if (! $this->owns($row, $mapping, $claim)) {
                return false;
            }
            $stillDelete = $booking instanceof Booking && in_array($booking->bookingStatus()->value, self::DELETABLE, true);
            if (! $booking instanceof Booking
                || (int) $mapping->last_requested_revision !== $request->revision
                || $stillDelete !== ($request->operation === GoogleCalendarOperation::Delete)) {
                if ($row->uncertain_since === null) {
                    $row->uncertain_since = now();
                }
                $row->requested_generation = max((int) $row->requested_generation, $claim->generation + 1);
                $row->available_at = now();
                $row->lease_token = null;
                $row->locked_until = null;
                $row->claimed_generation = null;
                $row->save();
                $mapping->sync_lease_token = null;
                $mapping->sync_locked_until = null;
                $mapping->save();
                return false;
            }
            $mapping->google_event_id = $request->googleEventId;
            $mapping->sync_status = $request->operation === GoogleCalendarOperation::Delete
                ? GoogleCalendarSyncStatus::Deleted->value
                : GoogleCalendarSyncStatus::Synced->value;
            $mapping->last_applied_revision = $request->revision;
            $mapping->last_synced_at = now();
            if ($remoteStateVerified) {
                $mapping->last_remote_verified_at = now();
            }
            $mapping->last_error = null;
            $mapping->sync_lease_token = null;
            $mapping->sync_locked_until = null;
            $mapping->save();
            $this->finishGeneration($row, $claim);
            return true;
        }, 3);
        if (! $ok) {
            $this->reconciliations->request(Booking::query()->findOrFail($claim->bookingId), GoogleCalendarEventType::from($claim->eventType));
        }
        return $ok;
    }

    private function completeWithoutRemoteOperation(GoogleCalendarReconciliationClaim $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            Booking::query()->lockForUpdate()->find($claim->bookingId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $claim->bookingId)->where('event_type', $claim->eventType)->lockForUpdate()->first();
            $row = GoogleCalendarReconciliation::query()->lockForUpdate()->find($claim->reconciliationId);
            if (! $this->owns($row, $mapping, $claim)) {
                return false;
            }
            $mapping->sync_lease_token = null;
            $mapping->sync_locked_until = null;
            $mapping->save();
            $this->finishGeneration($row, $claim);
            return true;
        }, 3);
    }

    private function finishGeneration(GoogleCalendarReconciliation $row, GoogleCalendarReconciliationClaim $claim): void
    {
        $row->completed_generation = max((int) $row->completed_generation, $claim->generation);
        $row->claimed_generation = null;
        $row->lease_token = null;
        $row->locked_until = null;
        $row->retry_count = 0;
        if ($row->unexpected_event_id === null) {
            $row->last_error = null;
        }
        if ((int) $row->requested_generation > $claim->generation) {
            // A new committed trigger already requested a follow-up; process it promptly.
            $row->available_at = now();
        } elseif ($row->uncertain_since !== null) {
            // A successful repair cannot prove an older accepted request will not
            // arrive late. Keep one durable generation scheduled, indefinitely,
            // with a configurable capped interval until an operator resolves it.
            $row->requested_generation = $claim->generation + 1;
            $row->available_at = now()->addSeconds($this->convergenceDelay((int) $row->convergence_checks));
        }
        if ($row->uncertain_since !== null) {
            $row->last_convergence_at = now();
            $row->convergence_checks = (int) $row->convergence_checks + 1;
        }
        $row->save();
    }

    private function fail(GoogleCalendarReconciliationClaim $claim, string $message, bool $retryable, ?string $unexpectedEventId = null, bool $remoteOutcomeUnknown = false): bool
    {
        $notice = null;
        $updated = DB::transaction(function () use ($claim, $message, $retryable, $unexpectedEventId, $remoteOutcomeUnknown, &$notice): bool {
            $booking = Booking::query()->lockForUpdate()->find($claim->bookingId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $claim->bookingId)->where('event_type', $claim->eventType)->lockForUpdate()->first();
            $row = GoogleCalendarReconciliation::query()->lockForUpdate()->find($claim->reconciliationId);
            if (! $this->owns($row, $mapping, $claim)) {
                return false;
            }
            $attempt = (int) $row->retry_count + 1;
            $row->retry_count = $attempt;
            $row->convergence_failures = (int) $row->convergence_failures + 1;
            if ($remoteOutcomeUnknown && $row->uncertain_since === null) {
                $row->uncertain_since = now();
            }
            $row->last_error = mb_substr($message, 0, 2000);
            if ($unexpectedEventId !== null) {
                $row->unexpected_event_id = $unexpectedEventId;
                $row->manual_review_required = true;
                if ($row->uncertain_since === null) {
                    $row->uncertain_since = now();
                }
            }
            $manualReviewAfter = max(1, (int) config('google_calendar.convergence.manual_review_after_failures', 10));
            if ((int) $row->convergence_failures >= $manualReviewAfter) {
                $row->manual_review_required = true;
            }
            $row->available_at = now()->addSeconds($this->backoffSeconds($attempt, $retryable ? null : 3600));
            $row->claimed_generation = null;
            $row->lease_token = null;
            $row->locked_until = null;
            $row->save();
            $mapping->last_error = $row->last_error;
            $mapping->sync_lease_token = null;
            $mapping->sync_locked_until = null;
            $mapping->save();
            if ($attempt >= 10 || $unexpectedEventId !== null || $row->manual_review_required) {
                $notice = [
                    'key' => "google-calendar:reconciliation:{$row->id}:generation:{$claim->generation}:failed",
                    'payload' => [
                        'booking_public_id' => $booking?->public_id,
                        'event_type' => $row->event_type,
                        'operation' => $remoteOutcomeUnknown ? 'uncertain' : 'reconcile',
                        'revision' => (int) $mapping->last_requested_revision,
                        'attempts' => $attempt,
                    ],
                ];
            }
            Log::error('google_calendar.reconciliation_failed', [
                'reconciliation_id' => $row->id,
                'event_type' => $row->event_type,
                'attempt' => $attempt,
                'remote_outcome_unknown' => $remoteOutcomeUnknown,
                'unexpected_event_id' => $unexpectedEventId,
                'error' => $row->last_error,
            ]);
            return true;
        }, 3);

        // Keep the durable retry/uncertainty state even if the notification store is down.
        if ($updated && is_array($notice)) {
            try {
                $this->notifications->enqueueAdmins('google_calendar.sync_failed', $notice['key'], $notice['payload']);
            } catch (Throwable $exception) {
                Log::warning('google_calendar.reconciliation_notice_failed', [
                    'reconciliation_id' => $claim->reconciliationId,
                    'generation' => $claim->generation,
                    'error' => class_basename($exception),
                ]);
            }
        }

        return false;
    }

    private function owns(?GoogleCalendarReconciliation $row, ?GoogleCalendarEvent $mapping, GoogleCalendarReconciliationClaim $claim): bool
    {
        return $row instanceof GoogleCalendarReconciliation
            && $mapping instanceof GoogleCalendarEvent
            && (int) $row->claimed_generation === $claim->generation
            && $row->locked_until?->isFuture() === true
            && hash_equals((string) $row->lease_token, $claim->leaseToken)
            && $mapping->sync_locked_until?->isFuture() === true
            && hash_equals((string) $mapping->sync_lease_token, $claim->leaseToken);
    }

    private function backoffSeconds(int $attempt, ?int $maximum = null): int
    {
        $base = max(1, (int) config('google_calendar.outbox.retry_base_seconds', 30));
        $maximum ??= max($base, (int) config('google_calendar.outbox.retry_max_seconds', 3600));
        $delay = min($maximum, $base * (2 ** min(20, max(0, $attempt - 1))));
        return min($maximum, $delay + random_int(0, max(1, intdiv($delay, 4))));
    }

    private function convergenceDelay(int $completedChecks): int
    {
        $base = max(1, (int) config('google_calendar.convergence.delay_seconds', 300));
        $maximum = max($base, (int) config('google_calendar.convergence.max_delay_seconds', 86400));
        $exponent = min(20, max(0, $completedChecks));

        return min($maximum, $base * (2 ** $exponent));
    }
}
