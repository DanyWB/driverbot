<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOutboxClaim;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Models\Booking;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarOutboxWorker
{
    private const MAX_ATTEMPTS = 10;
    private const DELETE_BOOKING_STATUSES = [
        BookingStatus::Cancelled->value,
        BookingStatus::CancelledByClient->value,
        BookingStatus::CancelledUnavailable->value,
        BookingStatus::Expired->value,
    ];

    public function __construct(
        private readonly GoogleCalendarOperationProcessor $processor,
        private readonly GoogleCalendarEventPayloadBuilder $payloadBuilder,
        private readonly NotificationOutboxService $notifications,
        private readonly GoogleCalendarEventIdentity $eventIdentity,
        private readonly GoogleCalendarReconciliationService $reconciliations,
        private readonly GoogleCalendarReconciliationWorker $reconciliationWorker,
    ) {}

    /** Claims one eligible operation; exposing this boundary also makes lease contention testable. */
    public function claimNext(): ?GoogleCalendarOutboxClaim
    {
        $driver = DB::getDriverName();
        $now = now();
        $maxAttempts = self::MAX_ATTEMPTS;

        for ($scan = 0; $scan < 1000; $scan++) {
            $result = DB::transaction(function () use ($driver, $now, $maxAttempts) {
                $candidateQuery = GoogleCalendarOutbox::query()
                    ->where('status', GoogleCalendarOutbox::STATUS_PENDING)
                    ->where('available_at', '<=', $now)
                    ->whereNotExists(function ($query) use ($now): void {
                        $query->selectRaw('1')
                            ->from('google_calendar_events as active_calendar_lease')
                            ->whereColumn('active_calendar_lease.booking_id', 'google_calendar_outbox.booking_id')
                            ->whereColumn('active_calendar_lease.event_type', 'google_calendar_outbox.event_type')
                            ->whereNotNull('active_calendar_lease.sync_lease_token')
                            ->where('active_calendar_lease.sync_locked_until', '>', $now);
                    })
                    ->orderBy('id');

                // PostgreSQL supports row-level SKIP LOCKED. Other drivers use ordinary row locks;
                // SQLite additionally relies on the conditional updates below because its row locks are no-ops.
                if ($driver === 'pgsql') {
                    $candidateQuery->lock('FOR UPDATE SKIP LOCKED');
                } elseif ($driver !== 'sqlite') {
                    $candidateQuery->lockForUpdate();
                }

                $outbox = $candidateQuery->first();
                if (! $outbox instanceof GoogleCalendarOutbox) {
                    return null;
                }

                $mappingQuery = GoogleCalendarEvent::query()
                    ->where('booking_id', $outbox->booking_id)
                    ->where('event_type', $outbox->event_type);
                if ($driver !== 'sqlite') {
                    $mappingQuery->lockForUpdate();
                }
                $mapping = $mappingQuery->first();

                if ($mapping instanceof GoogleCalendarEvent
                    && $mapping->sync_lease_token !== null
                    && $mapping->sync_locked_until?->isFuture()) {
                    return null;
                }

                if ((int) $outbox->retry_count >= $maxAttempts) {
                    $booking = $outbox->booking()->first();
                    if (! $mapping instanceof GoogleCalendarEvent || ! $booking instanceof Booking
                        || $this->isAuthoritative($outbox, $mapping, $booking)) {
                        $this->markExhaustedLease($outbox, $mapping);
                    } else {
                        $this->markSuperseded($outbox, $mapping, (string) $outbox->lease_token);
                    }

                    return 'exhausted';
                }

                $token = (string) Str::uuid();
                $leaseUntil = now()->addSeconds(max(15, (int) config('google_calendar.outbox.lease_seconds', 120)));
                $claimOutbox = GoogleCalendarOutbox::query()->whereKey($outbox->id);
                $claimOutbox->where('status', GoogleCalendarOutbox::STATUS_PENDING)
                    ->where('available_at', '<=', $now);

                $outboxClaimed = $claimOutbox->update([
                    'status' => GoogleCalendarOutbox::STATUS_PROCESSING,
                    'retry_count' => (int) $outbox->retry_count + 1,
                    'lease_token' => $token,
                    'locked_until' => $leaseUntil,
                    'updated_at' => now(),
                ]);
                if ($outboxClaimed !== 1) {
                    return null;
                }

                if ($mapping instanceof GoogleCalendarEvent) {
                    $mappingClaimed = GoogleCalendarEvent::query()->whereKey($mapping->id)
                        ->where(function (Builder $lease): void {
                            $lease->whereNull('sync_lease_token')
                                ->orWhereNull('sync_locked_until')
                                ->orWhere('sync_locked_until', '<=', now());
                        })
                        ->update([
                            'sync_lease_token' => $token,
                            'sync_locked_until' => $leaseUntil,
                            'updated_at' => now(),
                        ]);
                    if ($mappingClaimed !== 1) {
                        throw new \RuntimeException('Calendar event lease was acquired concurrently.');
                    }
                }

                return new GoogleCalendarOutboxClaim((int) $outbox->id, $token);
            }, 3);

            if ($result === 'exhausted') {
                continue;
            }

            return $result instanceof GoogleCalendarOutboxClaim ? $result : null;
        }

        return null;
    }

    /** @return array{claimed: int, processed: int, failed: int} */
    public function processBatch(?int $limit = null): array
    {
        $limit = min(1000, max(1, $limit ?? (int) config('google_calendar.outbox.batch_size', 50)));
        $this->recoverExpiredProcessing($limit);
        $reconciled = $this->reconciliationWorker->processBatch($limit);
        $counts = ['claimed' => 0, 'processed' => 0, 'failed' => 0];
        $counts['claimed'] += $reconciled['claimed'];
        $counts['processed'] += $reconciled['processed'];
        $counts['failed'] += $reconciled['failed'];

        for ($i = $counts['claimed']; $i < $limit; $i++) {
            $claim = $this->claimNext();
            if ($claim === null) {
                break;
            }
            $counts['claimed']++;
            $counts[$this->processClaim($claim) ? 'processed' : 'failed']++;
        }

        return $counts;
    }

    public function processClaim(GoogleCalendarOutboxClaim $claim): bool
    {
        $operationRequest = DB::transaction(function () use ($claim): GoogleCalendarOperationRequest|string|null {
            $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($claim->outboxId);
            if (! $this->ownsOutboxLease($outbox, $claim->leaseToken)) {
                return null;
            }

            $mappingQuery = GoogleCalendarEvent::query()
                ->where('booking_id', $outbox->booking_id)
                ->where('event_type', $outbox->event_type);
            if (DB::getDriverName() !== 'sqlite') {
                $mappingQuery->lockForUpdate();
            }
            $mapping = $mappingQuery->first();
            if (! $mapping instanceof GoogleCalendarEvent) {
                return 'The event mapping no longer exists.';
            }
            if ((string) $mapping->sync_lease_token !== $claim->leaseToken
                || ! $mapping->sync_locked_until?->isFuture()) {
                return null;
            }

            $booking = Booking::query()->with(['customer', 'vehicle'])->find($outbox->booking_id);
            if (! $booking instanceof Booking) {
                return 'The booking no longer exists.';
            }

            if (! $this->isAuthoritative($outbox, $mapping, $booking)) {
                $this->markSuperseded($outbox, $mapping, $claim->leaseToken);

                return null;
            }

            $operation = GoogleCalendarOperation::from((string) $outbox->operation);
            if ($operation === GoogleCalendarOperation::Delete) {
                if ($mapping->sync_status === GoogleCalendarSyncStatus::Deleted->value) {
                    $this->markSuccess($outbox, $mapping, $claim->leaseToken);

                    return null;
                }
                if ($mapping->sync_status !== GoogleCalendarSyncStatus::DeletePending->value) {
                    return 'Deletion mapping is not tombstoned.';
                }
            }

            $eventType = GoogleCalendarEventType::from((string) $outbox->event_type);
            if (! $mapping->google_event_id) {
                $mapping->google_event_id = $this->eventIdentity->for($booking, $eventType);
                $mapping->save();
            }
            try {
                $payload = $operation === GoogleCalendarOperation::Upsert
                    ? $this->payloadBuilder->build($booking, $eventType)
                    : null;
            } catch (\InvalidArgumentException $exception) {
                return 'Invalid calendar event data: '.$exception->getMessage();
            }

            return new GoogleCalendarOperationRequest(
                (string) $booking->public_id,
                $eventType,
                $operation,
                (string) $mapping->calendar_id,
                (string) $mapping->google_event_id,
                (int) $outbox->event_revision,
                $payload,
            );
        }, 3);

        if (is_string($operationRequest)) {
            return $this->recordFailure($claim, $operationRequest, false);
        }
        if (! $operationRequest instanceof GoogleCalendarOperationRequest) {
            return true;
        }

        $leaseSeconds = max(15, (int) config('google_calendar.outbox.lease_seconds', 120));
        $timeoutSeconds = max(1, min(
            (int) config('google_calendar.outbox.processor_timeout_seconds', 45),
            $leaseSeconds - 10,
        ));
        $context = new GoogleCalendarOperationContext(
            $timeoutSeconds,
            fn (): bool => $this->renewLease($claim, $leaseSeconds),
        );

        try {
            if (! $context->renewLease()) {
                return false;
            }
            $result = $this->processor->process($operationRequest, $context);
            if ($result->googleEventId !== null && ! hash_equals($operationRequest->googleEventId, $result->googleEventId)) {
                return $this->abandonWithReconciliation(
                    $claim,
                    'Processor returned an event ID different from the stable requested identity.',
                    $result->googleEventId,
                );
            }

            $successful = $this->markClaimSuccessful($claim, $operationRequest, $result->remoteStateVerified);
            if (! $successful) {
                $this->requestReconciliation($claim);
            }
            return $successful;
        } catch (GoogleCalendarProcessingException $exception) {
            if ($exception->remoteOutcomeUnknown) {
                return $this->abandonWithReconciliation($claim, $exception->getMessage());
            }
            return $this->recordFailure($claim, $exception->getMessage(), $exception->retryable);
        } catch (Throwable $exception) {
            report($exception);
            return $this->abandonWithReconciliation($claim, 'Unclassified processing failure: '.class_basename($exception));
        }
    }

    private function markClaimSuccessful(GoogleCalendarOutboxClaim $claim, GoogleCalendarOperationRequest $request, bool $remoteStateVerified): bool
    {
        return DB::transaction(function () use ($claim, $request, $remoteStateVerified): bool {
            $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($claim->outboxId);
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $outbox?->booking_id)
                ->where('event_type', $outbox?->event_type)->lockForUpdate()->first();
            if (! $this->ownsOutboxLease($outbox, $claim->leaseToken)
                || ! $mapping instanceof GoogleCalendarEvent
                || (string) $mapping->sync_lease_token !== $claim->leaseToken
                || ! $mapping->sync_locked_until?->isFuture()) {
                return false;
            }

            if ((int) $mapping->last_requested_revision !== $request->revision) {
                $this->markSuperseded($outbox, $mapping, $claim->leaseToken);

                return false;
            }

            $status = $request->operation === GoogleCalendarOperation::Delete
                ? GoogleCalendarSyncStatus::Deleted->value
                : GoogleCalendarSyncStatus::Synced->value;
            $mapping->forceFill([
                // The event ID is the permanent remote identity, including after deletion.
                'google_event_id' => $request->googleEventId,
                'sync_status' => $status,
                'last_applied_revision' => $request->revision,
                'last_error' => null,
                'last_synced_at' => now(),
                'last_remote_verified_at' => $remoteStateVerified ? now() : $mapping->last_remote_verified_at,
                'sync_lease_token' => null,
                'sync_locked_until' => null,
            ])->save();


            $outbox->forceFill([
                'status' => GoogleCalendarOutbox::STATUS_PROCESSED,
                'processed_at' => now(),
                'last_error' => null,
                'lease_token' => null,
                'locked_until' => null,
            ])->save();

            return true;
        }, 3);
    }

    private function renewLease(GoogleCalendarOutboxClaim $claim, int $leaseSeconds): bool
    {
        return DB::transaction(function () use ($claim, $leaseSeconds): bool {
            $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($claim->outboxId);
            if (! $this->ownsOutboxLease($outbox, $claim->leaseToken)) {
                return false;
            }
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $outbox->booking_id)
                ->where('event_type', $outbox->event_type)->lockForUpdate()->first();
            if (! $mapping instanceof GoogleCalendarEvent
                || ! hash_equals((string) $mapping->sync_lease_token, $claim->leaseToken)
                || ! $mapping->sync_locked_until?->isFuture()) {
                return false;
            }
            $until = now()->addSeconds($leaseSeconds);
            $outbox->forceFill(['locked_until' => $until])->save();
            $mapping->forceFill(['sync_locked_until' => $until])->save();

            return true;
        }, 3);
    }

    private function requestReconciliation(GoogleCalendarOutboxClaim $claim): void
    {
        $outbox = GoogleCalendarOutbox::query()->find($claim->outboxId);
        $booking = $outbox?->booking()->first();
        if (! $outbox instanceof GoogleCalendarOutbox || ! $booking instanceof Booking) {
            return;
        }

        $eventType = GoogleCalendarEventType::tryFrom((string) $outbox->event_type);
        if (! $eventType instanceof GoogleCalendarEventType) {
            return;
        }

        $this->reconciliations->request($booking, $eventType, remoteOutcomeUnknown: true);
    }

    /** Persist a repair request with the abandoned operation in one transaction. */
    private function abandonWithReconciliation(
        GoogleCalendarOutboxClaim $claim,
        string $reason,
        ?string $unexpectedEventId = null,
    ): bool {
        $notice = null;
        DB::transaction(function () use ($claim, $reason, $unexpectedEventId, &$notice): void {
            $initial = GoogleCalendarOutbox::query()->find($claim->outboxId);
            if (! $initial instanceof GoogleCalendarOutbox) {
                return;
            }
            $booking = Booking::query()->lockForUpdate()->find($initial->booking_id);
            $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($claim->outboxId);
            if (! $booking instanceof Booking || ! $outbox instanceof GoogleCalendarOutbox) {
                return;
            }
            $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->getKey())
                ->where('event_type', $outbox->event_type)->lockForUpdate()->first();
            $eventType = GoogleCalendarEventType::tryFrom((string) $outbox->event_type);
            if (! $mapping instanceof GoogleCalendarEvent || ! $eventType instanceof GoogleCalendarEventType) {
                return;
            }

            $this->reconciliations->requestInTransaction(
                $booking,
                $mapping,
                $eventType,
                $reason,
                $unexpectedEventId,
                remoteOutcomeUnknown: true,
            );
            if ($unexpectedEventId !== null) {
                $notice = [
                    'key' => "google-calendar:unexpected-event-id:outbox:{$outbox->id}",
                    'payload' => [
                        'booking_public_id' => $booking->public_id,
                        'event_type' => $eventType->value,
                        'operation' => $outbox->operation,
                        'revision' => (int) $outbox->event_revision,
                        'attempts' => (int) $outbox->retry_count,
                    ],
                ];
            }
            if ($this->ownsOutboxLease($outbox, $claim->leaseToken)) {
                $outbox->forceFill([
                    'status' => GoogleCalendarOutbox::STATUS_PROCESSED,
                    'processed_at' => now(),
                    'last_error' => mb_substr($reason, 0, 2000),
                    'lease_token' => null,
                    'locked_until' => null,
                ])->save();
            }
            if ((string) $mapping->sync_lease_token === $claim->leaseToken) {
                $mapping->forceFill(['sync_lease_token' => null, 'sync_locked_until' => null])->save();
            }

        }, 3);

        // Notification delivery is secondary. A notification-store failure must not
        // roll back the durable uncertain-operation marker or its repair request.
        if (is_array($notice)) {
            try {
                $this->notifications->enqueueAdmins('google_calendar.sync_failed', $notice['key'], $notice['payload']);
            } catch (Throwable $exception) {
                Log::warning('google_calendar.uncertain_operation_notice_failed', [
                    'outbox_id' => $claim->outboxId,
                    'error' => class_basename($exception),
                ]);
            }
        }

        return false;
    }

    /** Expired work may have reached the remote service; reconcile current desired state instead of replaying it. */
    private function recoverExpiredProcessing(int $limit): void
    {
        $ids = GoogleCalendarOutbox::query()
            ->where('status', GoogleCalendarOutbox::STATUS_PROCESSING)
            ->where(function (Builder $lease): void {
                $lease->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $initial = GoogleCalendarOutbox::query()->find($id);
                if (! $initial instanceof GoogleCalendarOutbox) {
                    return;
                }
                $booking = Booking::query()->lockForUpdate()->find($initial->booking_id);
                $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($id);
                if (! $booking instanceof Booking || ! $outbox instanceof GoogleCalendarOutbox
                    || $outbox->status !== GoogleCalendarOutbox::STATUS_PROCESSING
                    || ($outbox->locked_until !== null && $outbox->locked_until->isFuture())) {
                    return;
                }
                $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->getKey())
                    ->where('event_type', $outbox->event_type)->lockForUpdate()->first();
                if (! $mapping instanceof GoogleCalendarEvent) {
                    $outbox->forceFill([
                        'status' => GoogleCalendarOutbox::STATUS_FAILED,
                        'processed_at' => now(),
                        'last_error' => 'Expired work has no event mapping for recovery.',
                        'lease_token' => null,
                        'locked_until' => null,
                    ])->save();
                    return;
                }
                $eventType = GoogleCalendarEventType::tryFrom((string) $outbox->event_type);
                if (! $eventType instanceof GoogleCalendarEventType) {
                    return;
                }
                $this->reconciliations->requestInTransaction(
                    $booking,
                    $mapping,
                    $eventType,
                    'Processing lease expired; remote outcome may be unknown.',
                    remoteOutcomeUnknown: true,
                );
                $token = (string) $outbox->lease_token;
                $outbox->forceFill([
                    'status' => GoogleCalendarOutbox::STATUS_PROCESSED,
                    'processed_at' => now(),
                    'last_error' => 'Expired operation superseded by durable desired-state reconciliation.',
                    'lease_token' => null,
                    'locked_until' => null,
                ])->save();
                if ((string) $mapping->sync_lease_token === $token) {
                    $mapping->forceFill(['sync_lease_token' => null, 'sync_locked_until' => null])->save();
                }
            }, 3);
        }
    }

    private function recordFailure(GoogleCalendarOutboxClaim $claim, string $message, bool $retryable): bool
    {
        return DB::transaction(function () use ($claim, $message, $retryable): bool {
            $outbox = GoogleCalendarOutbox::query()->lockForUpdate()->find($claim->outboxId);
            if (! $this->ownsOutboxLease($outbox, $claim->leaseToken)) {
                return false;
            }

            $mapping = GoogleCalendarEvent::query()->where('booking_id', $outbox->booking_id)
                ->where('event_type', $outbox->event_type)->lockForUpdate()->first();
            if ($mapping instanceof GoogleCalendarEvent
                && (string) $mapping->sync_lease_token !== $claim->leaseToken) {
                return false;
            }

            $booking = $outbox->booking()->first();
            if ($mapping instanceof GoogleCalendarEvent && $booking instanceof Booking
                && ! $this->isAuthoritative($outbox, $mapping, $booking)) {
                $this->markSuperseded($outbox, $mapping, $claim->leaseToken);

                return true;
            }

            $maxAttempts = self::MAX_ATTEMPTS;
            $attempts = (int) $outbox->retry_count;
            $willRetry = $retryable && $attempts < $maxAttempts;
            $terminalMessage = mb_substr($message, 0, 2000);
            $outbox->forceFill([
                'status' => $willRetry ? GoogleCalendarOutbox::STATUS_PENDING : GoogleCalendarOutbox::STATUS_FAILED,
                'available_at' => $willRetry ? now()->addSeconds($this->backoffSeconds($attempts)) : $outbox->available_at,
                'processed_at' => $willRetry ? null : now(),
                'last_error' => $terminalMessage,
                'lease_token' => null,
                'locked_until' => null,
            ])->save();

            if ($mapping instanceof GoogleCalendarEvent && (string) $mapping->sync_lease_token === $claim->leaseToken) {
                $current = (int) $mapping->last_requested_revision === (int) $outbox->event_revision;
                $mapping->forceFill([
                    'sync_status' => ! $willRetry && $current && $outbox->operation === GoogleCalendarOperation::Upsert->value
                        && ! in_array($mapping->sync_status, [GoogleCalendarSyncStatus::DeletePending->value, GoogleCalendarSyncStatus::Deleted->value], true)
                        ? GoogleCalendarSyncStatus::Failed->value
                        : $mapping->sync_status,
                    'last_error' => $terminalMessage,
                    'sync_lease_token' => null,
                    'sync_locked_until' => null,
                ])->save();
            }

            if (! $willRetry) {
                $this->notifyAdministrators($outbox, $attempts);
            }

            Log::log($willRetry ? 'warning' : 'error', 'google_calendar.outbox_processing_failed', [
                'outbox_id' => $outbox->id,
                'operation' => $outbox->operation,
                'event_type' => $outbox->event_type,
                'attempts' => $attempts,
                'will_retry' => $willRetry,
                'error' => $terminalMessage,
            ]);

            return false;
        }, 3);
    }

    private function markSuperseded(GoogleCalendarOutbox $outbox, ?GoogleCalendarEvent $mapping, string $token): void
    {
        if ($mapping instanceof GoogleCalendarEvent && (string) $mapping->sync_lease_token === $token) {
            $mapping->forceFill(['sync_lease_token' => null, 'sync_locked_until' => null])->save();
        }
        if ((string) $outbox->lease_token === $token) {
            $outbox->forceFill([
                'status' => GoogleCalendarOutbox::STATUS_PROCESSED,
                'processed_at' => now(),
                'last_error' => 'Superseded by a newer revision or booking status.',
                'lease_token' => null,
                'locked_until' => null,
            ])->save();
        }
    }

    private function markSuccess(GoogleCalendarOutbox $outbox, GoogleCalendarEvent $mapping, string $token): void
    {
        if ((string) $mapping->sync_lease_token !== $token || (string) $outbox->lease_token !== $token) {
            return;
        }
        $mapping->forceFill([
            'sync_status' => GoogleCalendarSyncStatus::Deleted->value,
            'last_applied_revision' => $outbox->event_revision,
            'last_synced_at' => now(),
            'last_error' => null,
            'sync_lease_token' => null,
            'sync_locked_until' => null,
        ])->save();
        $outbox->forceFill([
            'status' => GoogleCalendarOutbox::STATUS_PROCESSED,
            'processed_at' => now(),
            'last_error' => null,
            'lease_token' => null,
            'locked_until' => null,
        ])->save();
    }

    private function markExhaustedLease(GoogleCalendarOutbox $outbox, ?GoogleCalendarEvent $mapping): void
    {
        $token = (string) $outbox->lease_token;
        $outbox->forceFill([
            'status' => GoogleCalendarOutbox::STATUS_FAILED,
            'processed_at' => now(),
            'last_error' => 'Processing lease expired after the maximum attempt count.',
            'lease_token' => null,
            'locked_until' => null,
        ])->save();

        if ($mapping instanceof GoogleCalendarEvent && (string) $mapping->sync_lease_token === $token) {
            $mapping->forceFill([
                'sync_status' => $outbox->operation === GoogleCalendarOperation::Upsert->value
                    && (int) $mapping->last_requested_revision === (int) $outbox->event_revision
                    && ! in_array($mapping->sync_status, [GoogleCalendarSyncStatus::DeletePending->value, GoogleCalendarSyncStatus::Deleted->value], true)
                        ? GoogleCalendarSyncStatus::Failed->value
                        : $mapping->sync_status,
                'last_error' => 'Processing lease expired after the maximum attempt count.',
                'sync_lease_token' => null,
                'sync_locked_until' => null,
            ])->save();
        }

        $this->notifyAdministrators($outbox, (int) $outbox->retry_count);
    }

    private function notifyAdministrators(GoogleCalendarOutbox $outbox, int $attempts): void
    {
        $booking = $outbox->booking()->first();
        $recipients = $this->notifications->enqueueAdmins(
            'google_calendar.sync_failed',
            "google-calendar:outbox:{$outbox->id}:failed",
            [
                'booking_public_id' => $booking?->public_id,
                'event_type' => $outbox->event_type,
                'operation' => $outbox->operation,
                'revision' => (int) $outbox->event_revision,
                'attempts' => $attempts,
            ],
        );
        if ($recipients === []) {
            Log::error('google_calendar.admin_failure_notice_has_no_recipient', ['outbox_id' => $outbox->id]);
        }
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = max(1, (int) config('google_calendar.outbox.retry_base_seconds', 30));
        $maximum = max($base, (int) config('google_calendar.outbox.retry_max_seconds', 3600));
        $exponent = min(20, max(0, $attempts - 1));
        $delay = min($maximum, $base * (2 ** $exponent));
        $jitter = random_int(0, max(1, intdiv($delay, 4)));

        return min($maximum, $delay + $jitter);
    }

    private function ownsOutboxLease(?GoogleCalendarOutbox $outbox, string $token): bool
    {
        return $outbox instanceof GoogleCalendarOutbox
            && $outbox->status === GoogleCalendarOutbox::STATUS_PROCESSING
            && hash_equals((string) $outbox->lease_token, $token)
            && $outbox->locked_until?->isFuture() === true;
    }

    private function isAuthoritative(GoogleCalendarOutbox $outbox, ?GoogleCalendarEvent $mapping, ?Booking $booking): bool
    {
        if (! $mapping instanceof GoogleCalendarEvent || ! $booking instanceof Booking
            || (int) $mapping->last_requested_revision !== (int) $outbox->event_revision) {
            return false;
        }

        $status = $booking->bookingStatus();
        if ($outbox->operation === GoogleCalendarOperation::Upsert->value) {
            return $status !== BookingStatus::Process
                && ! in_array($status->value, self::DELETE_BOOKING_STATUSES, true)
                && ! in_array($mapping->sync_status, [GoogleCalendarSyncStatus::DeletePending->value, GoogleCalendarSyncStatus::Deleted->value], true);
        }

        return in_array($status->value, self::DELETE_BOOKING_STATUSES, true)
            && in_array($mapping->sync_status, [GoogleCalendarSyncStatus::DeletePending->value, GoogleCalendarSyncStatus::Deleted->value], true);
    }
}
