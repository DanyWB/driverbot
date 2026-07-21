<?php

namespace App\Domain\Availability\Queries;

use App\Domain\Availability\Enums\OccupancyType;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AdminTimelineQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{vehicles: Collection<int, Vehicle>, occupancies: Collection<int, VehicleOccupancy>}
     */
    public function get(array $filters): array
    {
        $startsOn = (string) $filters['starts_on'];
        $endsOn = (string) $filters['ends_on'];
        $status = (string) ($filters['status'] ?? '');
        $vehicles = Vehicle::query()->with('category');

        $this->applyVehicleFilters($vehicles, $filters);

        if ((bool) ($filters['available_only'] ?? false)) {
            $vehicles->whereDoesntHave('occupancies', fn (Builder $query) => $this->overlapping($query, $startsOn, $endsOn));
        } elseif ($status !== '') {
            $vehicles->whereHas('occupancies', function (Builder $query) use ($startsOn, $endsOn, $status): void {
                $this->overlapping($query, $startsOn, $endsOn);
                $this->withStatus($query, $status);
            });
        }

        $vehicleRows = $vehicles
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($vehicleRows->isEmpty()) {
            return ['vehicles' => $vehicleRows, 'occupancies' => collect()];
        }

        $occupancies = VehicleOccupancy::query()
            ->whereIn('vehicle_id', $vehicleRows->pluck('id'))
            ->with('booking.customer')
            ->where(function (Builder $query) use ($startsOn, $endsOn): void {
                $this->overlapping($query, $startsOn, $endsOn);
            });

        if ($status !== '') {
            $this->withStatus($occupancies, $status);
        }

        $occupancyRows = $occupancies
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return ['vehicles' => $vehicleRows, 'occupancies' => $occupancyRows];
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyVehicleFilters(Builder $query, array $filters): void
    {
        if (is_string($filters['vehicle_type'] ?? null) && $filters['vehicle_type'] !== '') {
            $query->where('type', $filters['vehicle_type']);
        }

        if (is_numeric($filters['category_id'] ?? null)) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (is_numeric($filters['vehicle_id'] ?? null)) {
            $query->whereKey((int) $filters['vehicle_id']);
        }

        match ((string) ($filters['visibility'] ?? 'active')) {
            'visible' => $query->where('is_active', true)->where('is_visible_for_booking', true),
            'hidden' => $query->where('is_active', true)->where('is_visible_for_booking', false),
            'inactive' => $query->where('is_active', false),
            'all' => null,
            default => $query->where('is_active', true),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function overlapping(Builder $query, string $startsOn, string $endsOn): void
    {
        $query
            ->where('blocks_availability', true)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function withStatus(Builder $query, string $status): void
    {
        if ($status === 'maintenance') {
            $query->where('type', OccupancyType::Maintenance->value);

            return;
        }

        $query
            ->where('type', OccupancyType::Booking->value)
            ->whereHas('booking', fn (Builder $query) => $query->where('status', $status));
    }
}
