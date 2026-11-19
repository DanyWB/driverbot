<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarIdempotencyConflict;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarOutboxException;
use App\Models\Booking;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GoogleCalendarOutboxService
{
    public function __construct(private readonly GoogleCalendarEventIdentity $eventIdentity) {}

    public function upsert(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        string $calendarId,
        ?string $requestId = null,
    ): GoogleCalendarOutbox {
        $calendarId = trim($calendarId);

        if ($calendarId === '') {
            throw new InvalidArgumentException('A Google Calendar ID is required for an upsert.');
        }

        return DB::transaction(function () use ($booking, $eventType, $calendarId, $requestId): GoogleCalendarOutbox {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertCanUpsert($lockedBooking);

            $requestKey = $this->requestIdempotencyKey(
                $lockedBooking,
                $eventType,
                GoogleCalendarOperation::Upsert,
                $requestId,
            );

            if ($requestKey !== null) {
                $existing = $this->findExistingRequest($requestKey, $lockedBooking, $eventType, $calendarId);
                if ($existing instanceof GoogleCalendarOutbox) {
                    return $existing;
                }
            }

            $event = $this->lockOrCreateEvent($lockedBooking, $eventType, $calendarId);

            if (in_array($event->sync_status, [
                GoogleCalendarSyncStatus::DeletePending->value,
                GoogleCalendarSyncStatus::Deleted->value,
            ], true)) {
                throw new GoogleCalendarOutboxException(
                    'calendar_event_tombstoned',
                    'A deleted Google Calendar event cannot be upserted again.',
                );
            }

            if ((string) $event->calendar_id !== $calendarId) {
                throw new GoogleCalendarOutboxException(
                    'calendar_id_mismatch',
                    'A Google Calendar event mapping cannot be moved to a different calendar.',
                );
            }

            return $this->recordOperation(
                $event,
                $lockedBooking,
                $eventType,
                GoogleCalendarOperation::Upsert,
                GoogleCalendarSyncStatus::Pending,
                $requestId,
            );
        }, 3);
    }

    public function delete(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        ?string $calendarId = null,
        ?string $requestId = null,
    ): GoogleCalendarOutbox {
        $calendarId = $calendarId === null ? null : trim($calendarId);

        return DB::transaction(function () use ($booking, $eventType, $calendarId, $requestId): GoogleCalendarOutbox {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertCanDelete($lockedBooking);

            $requestKey = $this->requestIdempotencyKey(
                $lockedBooking,
                $eventType,
                GoogleCalendarOperation::Delete,
                $requestId,
            );

            if ($requestKey !== null) {
                $existing = $this->findExistingRequest($requestKey, $lockedBooking, $eventType, $calendarId);
                if ($existing instanceof GoogleCalendarOutbox) {
                    return $existing;
                }
            }

            $event = GoogleCalendarEvent::query()
                ->where('booking_id', $lockedBooking->getKey())
                ->where('event_type', $eventType->value)
                ->lockForUpdate()
                ->first();

            if (! $event instanceof GoogleCalendarEvent) {
                $calendarId = trim((string) $calendarId);

                if ($calendarId === '') {
                    throw new InvalidArgumentException('A Google Calendar ID is required to create a deletion tombstone.');
                }

                $event = GoogleCalendarEvent::query()->create([
                    'booking_id' => $lockedBooking->getKey(),
                    'event_type' => $eventType->value,
                    'calendar_id' => $calendarId,
                    'sync_status' => GoogleCalendarSyncStatus::Pending->value,
                    'last_requested_revision' => 0,
                    'last_applied_revision' => 0,
                ]);
            }

            if (in_array($event->sync_status, [
                GoogleCalendarSyncStatus::DeletePending->value,
                GoogleCalendarSyncStatus::Deleted->value,
            ], true)) {
                $latestDelete = GoogleCalendarOutbox::query()
                    ->where('booking_id', $lockedBooking->getKey())
                    ->where('event_type', $eventType->value)
                    ->where('event_revision', $event->last_requested_revision)
                    ->where('operation', GoogleCalendarOperation::Delete->value)
                    ->first();

                if ($latestDelete instanceof GoogleCalendarOutbox) {
                    return $latestDelete;
                }
            }

            return $this->recordOperation(
                $event,
                $lockedBooking,
                $eventType,
                GoogleCalendarOperation::Delete,
                GoogleCalendarSyncStatus::DeletePending,
                $requestId,
            );
        }, 3);
    }

    /** Queue a fresh operation from committed state after a remote result may be stale or uncertain. */
    public function reconcile(Booking $booking, GoogleCalendarEventType $eventType, string $requestId): ?GoogleCalendarOutbox
    {
        return DB::transaction(function () use ($booking, $eventType, $requestId): ?GoogleCalendarOutbox {
            $lockedBooking = $this->lockBooking($booking);
            $event = GoogleCalendarEvent::query()
                ->where('booking_id', $lockedBooking->getKey())
                ->where('event_type', $eventType->value)
                ->lockForUpdate()
                ->first();
            if (! $event instanceof GoogleCalendarEvent) {
                return null;
            }

            $delete = in_array($lockedBooking->bookingStatus()->value, [
                BookingStatus::Cancelled->value,
                BookingStatus::CancelledByClient->value,
                BookingStatus::CancelledUnavailable->value,
                BookingStatus::Expired->value,
            ], true);
            if (! $delete && $lockedBooking->bookingStatus() === BookingStatus::Process) {
                return null;
            }
            if (! $delete && in_array($event->sync_status, [
                GoogleCalendarSyncStatus::DeletePending->value,
                GoogleCalendarSyncStatus::Deleted->value,
            ], true)) {
                // Tombstones cannot be reopened by reconciliation or stale work.
                return null;
            }

            $operation = $delete ? GoogleCalendarOperation::Delete : GoogleCalendarOperation::Upsert;
            $key = (string) $this->requestIdempotencyKey($lockedBooking, $eventType, $operation, $requestId);
            $existing = $this->findExistingRequest($key, $lockedBooking, $eventType, (string) $event->calendar_id);
            if ($existing instanceof GoogleCalendarOutbox) {
                return $existing;
            }

            return $this->recordOperation(
                $event,
                $lockedBooking,
                $eventType,
                $operation,
                $delete ? GoogleCalendarSyncStatus::DeletePending : GoogleCalendarSyncStatus::Pending,
                $requestId,
            );
        }, 3);
    }

    private function lockBooking(Booking $booking): Booking
    {
        $locked = Booking::query()->lockForUpdate()->find($booking->getKey());

        if (! $locked instanceof Booking) {
            throw new GoogleCalendarOutboxException('booking_not_found', 'The booking no longer exists.');
        }

        return $locked;
    }

    private function assertCanUpsert(Booking $booking): void
    {
        if (in_array($booking->bookingStatus(), [
            BookingStatus::Process,
            BookingStatus::Cancelled,
            BookingStatus::CancelledByClient,
            BookingStatus::CancelledUnavailable,
            BookingStatus::Expired,
        ], true)) {
            throw new GoogleCalendarOutboxException(
                'booking_status_not_upsertable',
                'This booking status cannot have a Google Calendar event upserted.',
            );
        }
    }

    private function assertCanDelete(Booking $booking): void
    {
        if (in_array($booking->bookingStatus(), [BookingStatus::Completed, BookingStatus::NoShow], true)) {
            throw new GoogleCalendarOutboxException(
                'booking_history_must_be_retained',
                'Completed and no-show Google Calendar events must be retained.',
            );
        }

        if (! in_array($booking->bookingStatus(), [
            BookingStatus::Cancelled,
            BookingStatus::CancelledByClient,
            BookingStatus::CancelledUnavailable,
            BookingStatus::Expired,
        ], true)) {
            throw new GoogleCalendarOutboxException(
                'booking_status_not_deletable',
                'A Google Calendar event can only be deleted for a cancellation or expiry status.',
            );
        }
    }

    private function lockOrCreateEvent(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        string $calendarId,
    ): GoogleCalendarEvent {
        $event = GoogleCalendarEvent::query()
            ->where('booking_id', $booking->getKey())
            ->where('event_type', $eventType->value)
            ->lockForUpdate()
            ->first();

        if ($event instanceof GoogleCalendarEvent) {
            return $event;
        }

        return GoogleCalendarEvent::query()->create([
            'booking_id' => $booking->getKey(),
            'event_type' => $eventType->value,
            'calendar_id' => $calendarId,
            'sync_status' => GoogleCalendarSyncStatus::Pending->value,
            'last_requested_revision' => 0,
            'last_applied_revision' => 0,
        ]);
    }

    private function recordOperation(
        GoogleCalendarEvent $event,
        Booking $booking,
        GoogleCalendarEventType $eventType,
        GoogleCalendarOperation $operation,
        GoogleCalendarSyncStatus $syncStatus,
        ?string $requestId,
    ): GoogleCalendarOutbox {
        $revision = (int) $event->last_requested_revision + 1;
        if (! $event->google_event_id) {
            $event->google_event_id = $this->eventIdentity->for($booking, $eventType);
        }
        $event->forceFill([
            'sync_status' => $syncStatus->value,
            'last_requested_revision' => $revision,
            'last_error' => null,
        ])->save();

        $idempotencyKey = $this->requestIdempotencyKey($booking, $eventType, $operation, $requestId)
            ?? $this->revisionIdempotencyKey($booking, $eventType, $operation, $revision);

        return GoogleCalendarOutbox::query()->create([
            'booking_id' => $booking->getKey(),
            'event_type' => $eventType->value,
            'operation' => $operation->value,
            'event_revision' => $revision,
            'idempotency_key' => $idempotencyKey,
            'status' => GoogleCalendarOutbox::STATUS_PENDING,
            'retry_count' => 0,
            'available_at' => now(),
        ]);
    }

    private function findExistingRequest(
        string $key,
        Booking $booking,
        GoogleCalendarEventType $eventType,
        ?string $requestedCalendarId,
    ): ?GoogleCalendarOutbox
    {
        $existing = GoogleCalendarOutbox::query()->where('idempotency_key', $key)->first();

        if (! $existing instanceof GoogleCalendarOutbox) {
            return null;
        }

        $event = GoogleCalendarEvent::query()
            ->where('booking_id', $booking->getKey())
            ->where('event_type', $eventType->value)
            ->first();

        if (! $event instanceof GoogleCalendarEvent
            || ($requestedCalendarId !== null && $requestedCalendarId !== (string) $event->calendar_id)) {
            throw new GoogleCalendarIdempotencyConflict;
        }

        return $existing;
    }

    private function requestIdempotencyKey(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        GoogleCalendarOperation $operation,
        ?string $requestId,
    ): ?string {
        if ($requestId === null || trim($requestId) === '') {
            return null;
        }

        return sprintf(
            'gcal:%s:%s:%s:request:%s',
            $booking->public_id,
            $eventType->value,
            $operation->value,
            hash('sha256', trim($requestId)),
        );
    }

    private function revisionIdempotencyKey(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        GoogleCalendarOperation $operation,
        int $revision,
    ): string {
        return sprintf(
            'gcal:%s:%s:%s:revision:%d',
            $booking->public_id,
            $eventType->value,
            $operation->value,
            $revision,
        );
    }
}
