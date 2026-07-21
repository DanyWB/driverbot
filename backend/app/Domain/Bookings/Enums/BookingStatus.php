<?php

namespace App\Domain\Bookings\Enums;

enum BookingStatus: string
{
    case Process = 'process';
    case Pending = 'pending';
    case Approved = 'approved';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case CancelledByClient = 'cancelled_by_client';
    case Expired = 'expired';
    case NoShow = 'no_show';

    public function blocksAvailability(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Active], true);
    }

    public function canBecomeNoShow(): bool
    {
        return $this === self::Approved;
    }
}
