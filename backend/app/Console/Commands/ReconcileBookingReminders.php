<?php

namespace App\Console\Commands;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Models\Booking;
use Illuminate\Console\Command;

class ReconcileBookingReminders extends Command
{
    protected $signature = 'bookings:reconcile-reminders {--limit=500 : Maximum approved bookings to inspect}';

    protected $description = 'Reconcile scheduled pickup reminders for approved bookings';

    public function handle(NotificationOutboxService $outbox): int
    {
        $limit = min(5000, max(1, (int) $this->option('limit')));
        $timezone = (string) config('business.timezone', 'Asia/Bangkok');
        $horizon = max(1, (int) config('notifications.reminders.reconcile_horizon_days', 45));
        $ids = Booking::query()
            ->where('status', BookingStatus::Approved->value)
            ->whereBetween('starts_on', [now($timezone)->toDateString(), now($timezone)->addDays($horizon)->toDateString()])
            ->orderBy('starts_on')
            ->limit($limit)
            ->pluck('id');
        $reconciled = 0;

        foreach ($ids as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking || $booking->bookingStatus() !== BookingStatus::Approved) {
                continue;
            }

            $outbox->schedulePickupReminders($booking);
            $reconciled++;
        }

        $this->info("Approved bookings reconciled: {$reconciled}");

        return self::SUCCESS;
    }
}
