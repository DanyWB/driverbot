<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\Enums\OccupancyType;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use DateTimeInterface;
use Illuminate\Database\QueryException;

class BookingAvailabilityService
{
    public function isAvailable(
        Vehicle $vehicle,
        string $startsOn,
        string $endsOn,
        ?int $excludeBookingId = null,
    ): bool {
        try {
            $this->assertAvailable($vehicle, $startsOn, $endsOn, $excludeBookingId);

            return true;
        } catch (BookingException $exception) {
            if ($exception->errorCode === 'vehicle_unavailable') {
                return false;
            }

            throw $exception;
        }
    }

    public function lockVehicle(int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()->lockForUpdate()->find($vehicleId);

        if (! $vehicle instanceof Vehicle) {
            throw new BookingException('vehicle_not_found', 'Vehicle was not found.', 404, [
                'vehicle_id' => $vehicleId,
            ]);
        }

        return $vehicle;
    }

    public function assertAvailable(
        Vehicle $vehicle,
        string $startsOn,
        string $endsOn,
        ?int $excludeBookingId = null,
    ): void {
        $query = VehicleOccupancy::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('blocks_availability', true)
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn);

        if ($excludeBookingId !== null) {
            $query->where(function ($query) use ($excludeBookingId): void {
                $query->whereNull('booking_id')->orWhere('booking_id', '!=', $excludeBookingId);
            });
        }

        if ($query->exists()) {
            throw $this->unavailable($vehicle, $startsOn, $endsOn);
        }
    }

    public function syncBookingOccupancy(Booking $booking): ?VehicleOccupancy
    {
        if (! $booking->bookingStatus()->blocksAvailability()) {
            $booking->occupancy()->delete();

            return null;
        }

        return $booking->occupancy()->updateOrCreate([], [
            'vehicle_id' => $booking->vehicle_id,
            'type' => OccupancyType::Booking,
            'starts_on' => $this->bookingDate($booking, 'starts_on'),
            'ends_on' => $this->bookingDate($booking, 'ends_on'),
            'blocks_availability' => true,
            'label' => null,
            'created_by_admin_id' => $booking->created_by_admin_id,
            'metadata' => null,
        ]);
    }

    public function rethrowAsBookingConflict(QueryException $exception, int $vehicleId, string $startsOn, string $endsOn): never
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if ($sqlState === '23P01' || str_contains($exception->getMessage(), 'vehicle_occupancies_no_overlap')) {
            $vehicle = new Vehicle;
            $vehicle->id = $vehicleId;

            throw $this->unavailable($vehicle, $startsOn, $endsOn);
        }

        throw $exception;
    }

    private function unavailable(Vehicle $vehicle, string $startsOn, string $endsOn): BookingException
    {
        return new BookingException('vehicle_unavailable', 'Vehicle is unavailable for the selected dates.', 409, [
            'vehicle_id' => $vehicle->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    private function bookingDate(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $booking->getRawOriginal($attribute), 0, 10);
    }
}
