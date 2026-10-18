<?php

namespace App\Jobs;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireBookingRequest implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId) {}

    public function handle(BookingService $service): void
    {
        $booking = Booking::find($this->bookingId);
        if (! $booking || $booking->bookingStatus() !== BookingStatus::Pending || ! $booking->pendingExpiresAt()?->lessThanOrEqualTo(now())) {
            return;
        }
        try {
            $service->expire($booking);
        } catch (BookingException $exception) {
            if ($exception->errorCode !== 'booking_not_due_for_expiry') {
                throw $exception;
            }
        }
    }
}
