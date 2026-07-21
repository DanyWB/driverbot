<?php

namespace App\Console\Commands;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Models\Booking;
use Illuminate\Console\Command;

class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire-pending {--limit=500 : Maximum bookings to process}';

    protected $description = 'Expire pending bookings whose confirmation window elapsed';

    public function handle(BookingService $bookings): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = Booking::query()
            ->where('status', 'pending')
            ->whereNotNull('pending_expires_at')
            ->where('pending_expires_at', '<=', now())
            ->orderBy('pending_expires_at')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;

        foreach ($ids as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking) {
                continue;
            }

            try {
                $bookings->expire($booking);
                $expired++;
            } catch (BookingException $exception) {
                if (! in_array($exception->errorCode, ['booking_not_due_for_expiry', 'invalid_booking_transition'], true)) {
                    throw $exception;
                }
            }
        }

        $this->info("Expired bookings: {$expired}");

        return self::SUCCESS;
    }
}
