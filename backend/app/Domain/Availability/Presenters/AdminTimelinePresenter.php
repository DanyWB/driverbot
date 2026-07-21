<?php

namespace App\Domain\Availability\Presenters;

use App\Domain\Availability\Enums\OccupancyType;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

class AdminTimelinePresenter
{
    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @param  Collection<int, VehicleOccupancy>  $occupancies
     * @return array{range: array{starts_on: string, ends_on: string, days: int, today: string}, dates: list<array<string, mixed>>, rows: list<array<string, mixed>>, stats: array<string, int>}
     */
    public function present(Collection $vehicles, Collection $occupancies, string $startsOn, string $endsOn): array
    {
        $timezone = (string) config('business.timezone', 'Asia/Bangkok');
        $rangeStart = CarbonImmutable::parse($startsOn, $timezone)->startOfDay();
        $rangeEnd = CarbonImmutable::parse($endsOn, $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $dates = $this->dates($rangeStart, $rangeEnd, $today);
        $byVehicle = $occupancies->groupBy('vehicle_id');
        $occupiedVehicles = 0;

        $rows = array_values($vehicles->map(function (Vehicle $vehicle) use ($byVehicle, $rangeStart, $rangeEnd, &$occupiedVehicles): array {
            $vehicleOccupancies = $byVehicle->get($vehicle->id, collect());

            if ($vehicleOccupancies->isNotEmpty()) {
                $occupiedVehicles++;
            }

            $category = $vehicle->category;

            return [
                'id' => (int) $vehicle->id,
                'name' => (string) $vehicle->name,
                'type' => (string) $vehicle->getRawOriginal('type'),
                'inventory_code' => $this->nullableString($vehicle->inventory_code),
                'is_active' => (bool) $vehicle->is_active,
                'is_visible' => (bool) $vehicle->is_visible_for_booking,
                'category' => $category instanceof Category ? [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ] : null,
                'occupancies' => $vehicleOccupancies
                    ->map(fn (VehicleOccupancy $occupancy): array => $this->occupancy($occupancy, $rangeStart, $rangeEnd))
                    ->values()
                    ->all(),
            ];
        })->all());

        return [
            'range' => [
                'starts_on' => $rangeStart->toDateString(),
                'ends_on' => $rangeEnd->toDateString(),
                'days' => count($dates),
                'today' => $today,
            ],
            'dates' => $dates,
            'rows' => $rows,
            'stats' => [
                'vehicles' => $vehicles->count(),
                'occupied' => $occupiedVehicles,
                'available' => max(0, $vehicles->count() - $occupiedVehicles),
                'occupancies' => $occupancies->count(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function dates(CarbonImmutable $start, CarbonImmutable $end, string $today): array
    {
        $dates = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $dates[] = [
                'date' => $date->toDateString(),
                'day' => (int) $date->format('j'),
                'weekday' => $date->format('D'),
                'month' => $date->format('M'),
                'is_weekend' => $date->isWeekend(),
                'is_today' => $date->toDateString() === $today,
            ];
        }

        return $dates;
    }

    /** @return array<string, mixed> */
    private function occupancy(VehicleOccupancy $occupancy, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $start = $this->date($occupancy, 'starts_on');
        $end = $this->date($occupancy, 'ends_on');
        $visibleStart = $start->lessThan($rangeStart) ? $rangeStart : $start;
        $visibleEnd = $end->greaterThan($rangeEnd) ? $rangeEnd : $end;
        $booking = $occupancy->booking;
        $customer = $booking?->customer;
        $type = $occupancy->occupancyType();

        return [
            'id' => (int) $occupancy->id,
            'type' => $type->value,
            'status' => $type === OccupancyType::Maintenance
                ? 'maintenance'
                : $booking?->bookingStatus()->value,
            'label' => $type === OccupancyType::Maintenance
                ? ($this->nullableString($occupancy->label) ?? 'Maintenance')
                : ($customer instanceof Customer ? (string) $customer->name : 'Booking'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'start_index' => (int) $rangeStart->diffInDays($visibleStart),
            'span_days' => ((int) $visibleStart->diffInDays($visibleEnd)) + 1,
            'continues_before' => $start->lessThan($rangeStart),
            'continues_after' => $end->greaterThan($rangeEnd),
            'booking_public_id' => $booking instanceof Booking ? (string) $booking->public_id : null,
        ];
    }

    private function date(VehicleOccupancy $occupancy, string $attribute): CarbonImmutable
    {
        $value = $occupancy->getAttribute($attribute);
        $date = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $occupancy->getRawOriginal($attribute), 0, 10);

        return CarbonImmutable::parse($date, (string) config('business.timezone', 'Asia/Bangkok'))->startOfDay();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
