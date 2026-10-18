<?php

namespace App\Console\Commands;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\PendingRequestPolicy;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Models\Booking;
use Illuminate\Console\Command;

class ReconcileBookingReminders extends Command
{
    protected $signature = 'bookings:reconcile-reminders {--limit=500 : Maximum bookings per reminder family to inspect}';

    protected $description = 'Reconcile scheduled pending-request and pickup reminders';

    public function handle(NotificationOutboxService $outbox): int
    {
        $limit = min(5000, max(1, (int) $this->option('limit')));
        $timezone = (string) config('business.timezone', 'Asia/Bangkok');
        $horizon = max(1, (int) config('notifications.reminders.reconcile_horizon_days', 45));
        $approvedIds = Booking::query()
            ->where('status', BookingStatus::Approved->value)
            ->whereBetween('starts_on', [now($timezone)->toDateString(), now($timezone)->addDays($horizon)->toDateString()])
            ->orderBy('starts_on')
            ->limit($limit)
            ->pluck('id');
        $reconciled = 0;

        foreach ($approvedIds as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking || $booking->bookingStatus() !== BookingStatus::Approved) {
                continue;
            }

            $outbox->schedulePickupReminders($booking);
            $reconciled++;
        }

        $pendingIds = Booking::query()
            ->where('status', BookingStatus::Pending->value)
            ->where('request_policy_version', '>=', PendingRequestPolicy::VERSION)
            ->where('pending_expires_at', '>', now())
            ->orderBy('pending_expires_at')
            ->limit($limit)
            ->pluck('id');
        $pendingReconciled = 0;

        foreach ($pendingIds as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking || $booking->bookingStatus() !== BookingStatus::Pending) {
                continue;
            }

            $outbox->schedulePendingReminders($booking);
            $pendingReconciled++;
        }

        $this->info("Approved bookings reconciled: {$reconciled}");
        $this->info("Pending requests reconciled: {$pendingReconciled}");

        return self::SUCCESS;
    }
}
