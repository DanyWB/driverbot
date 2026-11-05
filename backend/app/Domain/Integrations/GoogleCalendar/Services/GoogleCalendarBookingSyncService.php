<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarOutboxException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GoogleCalendarEvent;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

class GoogleCalendarBookingSyncService
{
    private const TOMBSTONE_STATUSES = [
        GoogleCalendarSyncStatus::DeletePending->value,
        GoogleCalendarSyncStatus::Deleted->value,
    ];

    private const MAPPED_BOOKING_STATUSES = [
        BookingStatus::Pending->value,
        BookingStatus::Approved->value,
        BookingStatus::Active->value,
        BookingStatus::Completed->value,
        BookingStatus::NoShow->value,
    ];

    public function __construct(private readonly GoogleCalendarOutboxService $outbox) {}

    public function recordStatusChange(
        Booking $booking,
        ?BookingStatus $from,
        BookingStatus $to,
        ?string $requestId = null,
    ): void {
        if (in_array($to, [BookingStatus::Pending, BookingStatus::Approved], true)) {
            $this->upsertBoth($booking, $requestId);

            return;
        }

        if (in_array($to, [
            BookingStatus::Cancelled,
            BookingStatus::CancelledByClient,
            BookingStatus::CancelledUnavailable,
            BookingStatus::Expired,
        ], true)) {
            $this->deleteBoth($booking, $requestId);

            return;
        }

        if ($to === BookingStatus::NoShow) {
            $this->upsertMappedEvents($booking, $requestId);
        }
    }

    /** @param array<string, string|null> $oldValues @param array<string, string|null> $newValues */
    public function recordDateChanges(
        Booking $booking,
        array $oldValues,
        array $newValues,
        ?string $requestId = null,
    ): void {
        if ($oldValues['starts_on'] !== $newValues['starts_on']
            || $oldValues['pickup_time'] !== $newValues['pickup_time']) {
            $this->upsertMappedEvent($booking, GoogleCalendarEventType::Pickup, $requestId);
        }

        if ($oldValues['ends_on'] !== $newValues['ends_on']
            || $oldValues['return_time'] !== $newValues['return_time']) {
            $this->upsertMappedEvent($booking, GoogleCalendarEventType::ReturnEvent, $requestId);
        }
    }

    public function recordBookingContactNameChange(Booking $booking, ?string $requestId = null): void
    {
        $this->upsertMappedEvents($booking, $requestId);
    }

    public function recordCustomerNameChange(Customer $customer, ?string $requestId = null): void
    {
        $customer->bookings()
            ->whereIn('status', self::MAPPED_BOOKING_STATUSES)
            ->whereHas('googleCalendarEvents', fn (Builder $query) => $query->whereNotIn('sync_status', self::TOMBSTONE_STATUSES))
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($requestId): void {
                foreach ($bookings as $booking) {
                    $this->upsertMappedEvents($booking, $requestId);
                }
            });
    }

    public function recordVehicleNameChange(Vehicle $vehicle, ?string $requestId = null): void
    {
        $vehicle->bookings()
            ->whereIn('status', self::MAPPED_BOOKING_STATUSES)
            ->whereHas('googleCalendarEvents', fn (Builder $query) => $query->whereNotIn('sync_status', self::TOMBSTONE_STATUSES))
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($requestId): void {
                foreach ($bookings as $booking) {
                    $this->upsertMappedEvents($booking, $requestId);
                }
            });
    }

    private function upsertBoth(Booking $booking, ?string $requestId): void
    {
        foreach (GoogleCalendarEventType::cases() as $eventType) {
            $mapping = $this->mapping($booking, $eventType);
            $calendarId = $mapping?->calendar_id ?? $this->calendarId($eventType);
            $this->upsert($booking, $eventType, (string) $calendarId, $requestId);
        }
    }

    private function upsertMappedEvents(Booking $booking, ?string $requestId): void
    {
        foreach (GoogleCalendarEventType::cases() as $eventType) {
            $this->upsertMappedEvent($booking, $eventType, $requestId);
        }
    }

    private function upsertMappedEvent(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        ?string $requestId,
    ): void {
        $mapping = $this->mapping($booking, $eventType);

        if (! $mapping instanceof GoogleCalendarEvent || in_array($mapping->sync_status, self::TOMBSTONE_STATUSES, true)) {
            return;
        }

        $this->upsert($booking, $eventType, (string) $mapping->calendar_id, $requestId);
    }

    private function upsert(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        string $calendarId,
        ?string $requestId,
    ): void {
        try {
            $this->outbox->upsert($booking, $eventType, $calendarId, $requestId);
        } catch (GoogleCalendarOutboxException $exception) {
            if (in_array($exception->reason, ['booking_status_not_upsertable', 'calendar_event_tombstoned'], true)) {
                return;
            }

            throw $exception;
        }
    }

    private function deleteBoth(Booking $booking, ?string $requestId): void
    {
        foreach (GoogleCalendarEventType::cases() as $eventType) {
            $mapping = $this->mapping($booking, $eventType);
            $calendarId = $mapping?->calendar_id ?? $this->calendarId($eventType);
            $this->outbox->delete($booking, $eventType, (string) $calendarId, $requestId);
        }
    }

    private function mapping(Booking $booking, GoogleCalendarEventType $eventType): ?GoogleCalendarEvent
    {
        return GoogleCalendarEvent::query()
            ->where('booking_id', $booking->getKey())
            ->where('event_type', $eventType->value)
            ->first();
    }

    private function calendarId(GoogleCalendarEventType $eventType): string
    {
        $calendarId = config("google_calendar.calendars.{$eventType->value}");

        if (! is_string($calendarId) || trim($calendarId) === '') {
            throw new GoogleCalendarOutboxException(
                'calendar_id_not_configured',
                "The Google Calendar ID for {$eventType->value} is not configured.",
            );
        }

        return trim($calendarId);
    }
}
