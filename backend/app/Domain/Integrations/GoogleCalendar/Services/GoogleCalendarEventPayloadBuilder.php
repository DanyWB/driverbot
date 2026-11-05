<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class GoogleCalendarEventPayloadBuilder
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** @return array{summary: string, description: string, start: array<string, string>, end: array<string, string>} */
    public function build(Booking $booking, GoogleCalendarEventType $eventType): array
    {
        $booking->loadMissing(['customer', 'vehicle']);
        $dateAttribute = $eventType === GoogleCalendarEventType::Pickup ? 'starts_on' : 'ends_on';
        $timeAttribute = $eventType === GoogleCalendarEventType::Pickup ? 'pickup_time' : 'return_time';
        $date = $this->dateValue($booking, $dateAttribute);
        $time = $this->nullableTime($booking->getRawOriginal($timeAttribute));
        $statusText = $this->statusText($booking->bookingStatus());
        $customerName = trim((string) $booking->contact_name);
        if ($customerName === '') {
            $customerName = trim((string) $booking->customer->name);
        }

        $prefix = $eventType === GoogleCalendarEventType::Pickup ? '🏍' : '↩️';
        $payload = [
            'summary' => sprintf('%s %s | #%s | %s', $prefix, trim((string) $booking->vehicle->name), (string) $booking->public_id, $customerName),
            'description' => 'Status: '.$statusText,
        ];

        if ($time === null) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
            if (! $start instanceof CarbonImmutable) {
                throw new InvalidArgumentException('Booking event date is invalid.');
            }
            $end = $start->addDay();

            return [
                ...$payload,
                'start' => ['date' => $start->toDateString()],
                'end' => ['date' => $end->toDateString()],
            ];
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', "{$date} {$time}", self::TIMEZONE);
        if (! $start instanceof CarbonImmutable) {
            throw new InvalidArgumentException('Booking event date or time is invalid.');
        }
        $duration = max(1, (int) config('google_calendar.event_duration_minutes', 60));
        $end = $start->addMinutes($duration);

        return [
            ...$payload,
            'start' => ['dateTime' => $start->toIso8601String(), 'timeZone' => self::TIMEZONE],
            'end' => ['dateTime' => $end->toIso8601String(), 'timeZone' => self::TIMEZONE],
        ];
    }

    private function statusText(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Pending => 'PENDING APPROVAL',
            BookingStatus::NoShow => 'NO SHOW',
            // Active and completed bookings retain their approved operational event status.
            default => 'APPROVED',
        };
    }

    private function dateValue(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $booking->getRawOriginal($attribute), 0, 10);
    }

    private function nullableTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $time = trim($value);

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
