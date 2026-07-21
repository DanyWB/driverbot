<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Presenters\AdminBookingPresenter;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(AdminBookingPresenter $presenter): Response
    {
        $today = now((string) config('business.timezone'))->toDateString();
        $recent = Booking::query()
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value, BookingStatus::Active->value])
            ->with(['customer.contacts', 'vehicle', 'latestPriceSnapshot'])
            ->withCount('documents')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderBy('starts_on')
            ->limit(6)
            ->get()
            ->map(fn (Booking $booking): array => $presenter->listItem($booking));
        $activeVehicles = Vehicle::query()->where('is_active', true)->count();
        $occupiedToday = VehicleOccupancy::query()
            ->whereHas('vehicle', fn ($query) => $query->where('is_active', true))
            ->where('blocks_availability', true)
            ->where('starts_on', '<=', $today)
            ->where('ends_on', '>=', $today)
            ->distinct('vehicle_id')
            ->count('vehicle_id');

        return Inertia::render('Dashboard', [
            'summary' => [
                'pending' => Booking::query()->where('status', BookingStatus::Pending->value)->count(),
                'pickups_today' => Booking::query()
                    ->where('status', BookingStatus::Approved->value)
                    ->whereDate('starts_on', $today)
                    ->count(),
                'active' => Booking::query()->where('status', BookingStatus::Active->value)->count(),
                'available_vehicles' => max(0, $activeVehicles - $occupiedToday),
                'active_vehicles' => $activeVehicles,
            ],
            'bookings' => $recent,
        ]);
    }
}
