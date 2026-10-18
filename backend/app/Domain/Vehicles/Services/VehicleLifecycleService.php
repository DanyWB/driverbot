<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Shared\Services\AdminAuditService;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class VehicleLifecycleService
{
    public function __construct(private readonly AdminAuditService $audit, private readonly VehiclePhotoService $photos) {}

    public function apply(Vehicle $vehicle, string $action, User $admin, ?string $confirmation = null, ?string $requestId = null): void
    {
        DB::transaction(function () use ($vehicle, $action, $admin, $confirmation, $requestId): void {
            // Booking creation takes the same vehicle lock, preventing archive/create races.
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            $old = $locked->only(['name', 'archived_at', 'is_active', 'is_visible_for_booking']);
            switch ($action) {
                case 'archive':
                    if ($locked->unfinishedBookings()->exists()) {
                        $this->invalid('unfinished');
                    }
                    $locked->forceFill(['archived_at' => $locked->archived_at ?? now(), 'is_active' => false, 'is_visible_for_booking' => false])->save();
                    break;
                case 'restore':
                    if ($locked->archived_at === null) {
                        $this->invalid('not_archived');
                    }
                    $locked->forceFill(['archived_at' => null, 'is_active' => true, 'is_visible_for_booking' => false])->save();
                    break;
                case 'unpublish':
                    $locked->forceFill(['is_visible_for_booking' => false])->save();
                    break;
                case 'delete':
                    if ($locked->archived_at === null) {
                        $this->invalid('not_archived');
                    }
                    if ($confirmation !== $locked->name) {
                        $this->invalid('confirmation');
                    }
                    if ($locked->bookings()->exists() || $locked->occupancies()->exists()) {
                        $this->invalid('history');
                    }
                    $photos = $locked->photos()->get();
                    $this->audit->record($admin, $locked, 'vehicle.deleted', $old, null, $requestId);
                    $locked->delete();
                    DB::afterCommit(function () use ($photos): void {
                        foreach ($photos as $photo) {
                            $this->photos->discardFiles($photo);
                        }
                    });

                    return;
                default:
                    throw new InvalidArgumentException('Unknown vehicle lifecycle action.');
            }
            $this->audit->record($admin, $locked, 'vehicle.'.$action, $old, $locked->only(array_keys($old)), $requestId);
        }, 3);
    }

    private function invalid(string $key): never
    {
        $locale = request()->cookie('admin_locale') === 'ru' ? 'ru' : 'en';
        throw ValidationException::withMessages(['vehicle' => __('fleet.'.$key, [], $locale)]);
    }
}
