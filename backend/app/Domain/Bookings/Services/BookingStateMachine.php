<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;

class BookingStateMachine
{
    /** @var array<string, list<BookingStatus>> */
    private const TRANSITIONS = [
        'process' => [BookingStatus::Pending, BookingStatus::CancelledByClient],
        'pending' => [BookingStatus::Approved, BookingStatus::Cancelled, BookingStatus::CancelledByClient, BookingStatus::Expired],
        'approved' => [BookingStatus::Active, BookingStatus::Cancelled, BookingStatus::CancelledByClient, BookingStatus::NoShow],
        'active' => [BookingStatus::Completed, BookingStatus::Cancelled],
    ];

    /** @return list<BookingStatus> */
    public function allowedTransitions(BookingStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    public function canTransition(BookingStatus $from, BookingStatus $to): bool
    {
        return in_array($to, $this->allowedTransitions($from), true);
    }

    public function assertCanTransition(BookingStatus $from, BookingStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new BookingException(
                'invalid_booking_transition',
                "Booking cannot transition from {$from->value} to {$to->value}.",
                context: [
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ],
            );
        }
    }
}
