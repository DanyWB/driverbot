<?php

namespace App\Http\Controllers;

use App\Domain\Availability\Presenters\AdminTimelinePresenter;
use App\Domain\Availability\Queries\AdminTimelineQuery;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Http\Requests\TimelineRequest;
use App\Models\Category;
use App\Models\Vehicle;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function __invoke(
        TimelineRequest $request,
        AdminTimelineQuery $query,
        AdminTimelinePresenter $presenter,
    ): Response {
        $filters = $request->filters();
        $result = $query->get($filters);
        $timeline = $presenter->present(
            $result['vehicles'],
            $result['occupancies'],
            $result['blockingOccupancies'],
            (string) $filters['starts_on'],
            (string) $filters['ends_on'],
        );

        return Inertia::render('timeline/Index', [
            'timeline' => $timeline,
            'filters' => $filters,
            'options' => [
                'vehicle_types' => array_column(VehicleType::cases(), 'value'),
                'statuses' => [
                    BookingStatus::Pending->value,
                    BookingStatus::Approved->value,
                    BookingStatus::Active->value,
                    'maintenance',
                ],
                'categories' => Category::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'vehicle_type'])
                    ->map(fn (Category $category): array => [
                        'id' => (int) $category->id,
                        'name' => (string) $category->name,
                        'vehicle_type' => (string) $category->getRawOriginal('vehicle_type'),
                    ]),
                'vehicles' => Vehicle::query()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'category_id', 'is_active', 'is_visible_for_booking'])
                    ->map(fn (Vehicle $vehicle): array => [
                        'id' => (int) $vehicle->id,
                        'name' => (string) $vehicle->name,
                        'type' => (string) $vehicle->getRawOriginal('type'),
                        'category_id' => $vehicle->category_id === null ? null : (int) $vehicle->category_id,
                        'is_active' => (bool) $vehicle->is_active,
                        'is_visible' => (bool) $vehicle->is_visible_for_booking,
                    ]),
            ],
        ]);
    }
}
