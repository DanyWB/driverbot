<?php

namespace App\Domain\Availability\Services;

use App\Domain\Availability\Enums\OccupancyType;
use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\VehicleOccupancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MaintenanceService
{
    public function __construct(private readonly BookingAvailabilityService $availability) {}

    public function create(
        int $vehicleId,
        string $startsOn,
        string $endsOn,
        string $label,
        BookingActor $actor,
        ?string $requestId = null,
    ): VehicleOccupancy {
        $this->assertAdmin($actor);
        $this->assertPeriod($startsOn, $endsOn);
        $label = trim($label);

        if ($label === '') {
            throw new BookingException('maintenance_label_required', 'A maintenance label is required.', 422);
        }

        try {
            return DB::transaction(function () use ($vehicleId, $startsOn, $endsOn, $label, $actor, $requestId): VehicleOccupancy {
                $vehicle = $this->availability->lockVehicle($vehicleId);
                $this->availability->assertAvailable($vehicle, $startsOn, $endsOn);

                $occupancy = VehicleOccupancy::query()->create([
                    'vehicle_id' => $vehicle->id,
                    'booking_id' => null,
                    'type' => OccupancyType::Maintenance,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'blocks_availability' => true,
                    'label' => $label,
                    'created_by_admin_id' => $actor->adminId,
                ]);

                AuditLog::query()->create([
                    'actor_type' => $actor->type,
                    'actor_admin_id' => $actor->adminId,
                    'subject_type' => VehicleOccupancy::class,
                    'subject_id' => (string) $occupancy->id,
                    'action' => 'maintenance.created',
                    'new_values' => $this->values($occupancy),
                    'request_id' => $requestId,
                ]);

                return $occupancy;
            }, 3);
        } catch (QueryException $exception) {
            $this->availability->rethrowAsBookingConflict($exception, $vehicleId, $startsOn, $endsOn);
        }
    }

    public function remove(VehicleOccupancy $occupancy, BookingActor $actor, ?string $requestId = null): void
    {
        $this->assertAdmin($actor);

        DB::transaction(function () use ($occupancy, $actor, $requestId): void {
            $locked = VehicleOccupancy::query()->lockForUpdate()->find($occupancy->id);

            if (! $locked instanceof VehicleOccupancy || $locked->occupancyType() !== OccupancyType::Maintenance) {
                throw new BookingException('maintenance_not_found', 'Maintenance occupancy was not found.', 404);
            }

            $this->availability->lockVehicle((int) $locked->vehicle_id);
            $oldValues = $this->values($locked);
            $subjectId = (string) $locked->id;
            $locked->delete();

            AuditLog::query()->create([
                'actor_type' => $actor->type,
                'actor_admin_id' => $actor->adminId,
                'subject_type' => VehicleOccupancy::class,
                'subject_id' => $subjectId,
                'action' => 'maintenance.removed',
                'old_values' => $oldValues,
                'request_id' => $requestId,
            ]);
        }, 3);
    }

    private function assertAdmin(BookingActor $actor): void
    {
        if ($actor->type !== ActorType::Admin || $actor->adminId === null) {
            throw new BookingException('booking_actor_forbidden', 'Only an admin can manage maintenance.', 403);
        }
    }

    private function assertPeriod(string $startsOn, string $endsOn): void
    {
        try {
            RentalPeriod::fromStrings($startsOn, $endsOn, (string) config('business.timezone'));
        } catch (InvalidArgumentException $exception) {
            throw new BookingException('invalid_rental_period', $exception->getMessage(), 422, previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function values(VehicleOccupancy $occupancy): array
    {
        return [
            'vehicle_id' => (int) $occupancy->vehicle_id,
            'starts_on' => (string) $occupancy->getRawOriginal('starts_on'),
            'ends_on' => (string) $occupancy->getRawOriginal('ends_on'),
            'label' => (string) $occupancy->label,
            'blocks_availability' => (bool) $occupancy->blocks_availability,
        ];
    }
}
