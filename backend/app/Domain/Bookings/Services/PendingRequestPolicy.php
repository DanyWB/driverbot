<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Bookings\Exceptions\BookingException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class PendingRequestPolicy
{
    public const VERSION = 2;

    public const HOURS = 48;

    public function deadline(DateTimeInterface $submittedAt, string $startsOn, ?string $pickupTime): CarbonImmutable
    {
        $submitted = CarbonImmutable::instance($submittedAt);
        $start = CarbonImmutable::parse($startsOn.' '.($pickupTime ?: '12:00:00'), (string) config('business.timezone', 'Asia/Bangkok'));

        // Eloquent's database date format has no offset. Persist in the application's
        // timezone so a subsequent cast resolves the same instant, also with SQLite.
        return $submitted->addHours(self::HOURS)->min($start)->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public function assertOpen(CarbonImmutable $deadline): void
    {
        if (! $deadline->isFuture()) {
            throw new BookingException('booking_request_expired', 'The request deadline has passed. Select another rental date.', 409);
        }
    }

    /** @return list<CarbonImmutable> */
    public function reminderSlots(DateTimeInterface $submittedAt, DateTimeInterface $deadline): array
    {
        $submitted = CarbonImmutable::instance($submittedAt)->setTimezone((string) config('business.timezone', 'Asia/Bangkok'));
        $end = CarbonImmutable::instance($deadline);
        $slots = [];
        // The initial notification is recorded with the status event, not again here.
        for ($day = $submitted->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            foreach ([12, 18] as $hour) {
                if ($day->isSameDay($submitted) && $hour === 12) {
                    continue;
                }
                $slot = $day->setTime($hour, 0);
                if ($slot->gt($submitted) && $slot->lt($end)) {
                    $slots[] = $slot->utc();
                }
            }
        }

        return $slots;
    }
}
