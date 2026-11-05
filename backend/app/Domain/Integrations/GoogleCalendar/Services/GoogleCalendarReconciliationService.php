<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Models\Booking;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarReconciliation;
use Illuminate\Support\Facades\DB;

/** Persistent, coalescing dirty marker: one reconciliation record per booking/event type. */
class GoogleCalendarReconciliationService
{
    public function request(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        bool $remoteOutcomeUnknown = false,
    ): ?GoogleCalendarReconciliation
    {
        return DB::transaction(function () use ($booking, $eventType, $remoteOutcomeUnknown): ?GoogleCalendarReconciliation {
            $lockedBooking = Booking::query()->lockForUpdate()->find($booking->getKey());
            if (! $lockedBooking instanceof Booking) {
                return null;
            }

            $mapping = GoogleCalendarEvent::query()
                ->where('booking_id', $lockedBooking->getKey())
                ->where('event_type', $eventType->value)
                ->lockForUpdate()
                ->first();
            if (! $mapping instanceof GoogleCalendarEvent) {
                return null;
            }
            return $this->requestInTransaction(
                $lockedBooking,
                $mapping,
                $eventType,
                remoteOutcomeUnknown: $remoteOutcomeUnknown,
            );
        }, 3);
    }

    /** Caller must hold the booking and mapping locks in the current transaction. */
    public function requestInTransaction(
        Booking $booking,
        GoogleCalendarEvent $mapping,
        GoogleCalendarEventType $eventType,
        ?string $lastError = null,
        ?string $unexpectedEventId = null,
        bool $remoteOutcomeUnknown = false,
    ): GoogleCalendarReconciliation
    {
        $row = GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->getKey())
            ->where('event_type', $eventType->value)
            ->lockForUpdate()
            ->first();
        if (! $row instanceof GoogleCalendarReconciliation) {
            return GoogleCalendarReconciliation::query()->create([
                'booking_id' => $booking->getKey(),
                'event_type' => $eventType->value,
                'requested_generation' => 1,
                'completed_generation' => 0,
                'available_at' => now(),
                'last_error' => $lastError,
                'unexpected_event_id' => $unexpectedEventId,
                'uncertain_since' => $remoteOutcomeUnknown ? now() : null,
                'manual_review_required' => $unexpectedEventId !== null,
            ]);
        }

        $this->requestLocked($row);
        if ($lastError !== null) {
            $row->last_error = mb_substr($lastError, 0, 2000);
        }
        if ($unexpectedEventId !== null) {
            $row->unexpected_event_id = $unexpectedEventId;
            $row->manual_review_required = true;
        }
        if ($remoteOutcomeUnknown) {
            if ($row->uncertain_since === null) {
                $row->uncertain_since = now();
            }
        }
        $row->save();

        return $row;
    }

    /** Caller must hold the booking, mapping, and reconciliation row locks in the current transaction. */
    public function requestLocked(GoogleCalendarReconciliation $row): GoogleCalendarReconciliation
    {
        $requested = (int) $row->requested_generation;
        $completed = (int) $row->completed_generation;
        $claimed = $row->claimed_generation === null ? null : (int) $row->claimed_generation;
        $leaseActive = $row->lease_token !== null && $row->locked_until?->isFuture();

        // Coalesce triggers while queued. If one is in flight, record exactly one follow-up generation.
        if ($requested <= $completed) {
            $row->requested_generation = $completed + 1;
            $row->available_at = now();
            $row->retry_count = 0;
        } elseif ($leaseActive && $claimed !== null && $requested <= $claimed) {
            $row->requested_generation = $claimed + 1;
            $row->retry_count = 0;
        }

        $row->save();

        return $row;
    }
}
