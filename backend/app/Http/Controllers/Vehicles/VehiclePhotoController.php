<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Vehicles\Services\VehiclePhotoService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\ArrangeVehiclePhotosRequest;
use App\Http\Requests\Vehicles\StoreVehiclePhotoRequest;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VehiclePhotoController extends Controller
{
    use HandlesAdminContext;

    public function store(
        StoreVehiclePhotoRequest $request,
        Vehicle $vehicle,
        VehiclePhotoService $photos,
        AdminAuditService $audit,
    ): RedirectResponse {
        $file = $request->file('photo');
        abort_if($file === null, 422);

        $photo = null;

        try {
            DB::transaction(function () use ($request, $vehicle, $photos, $audit, $file, &$photo): void {
                $photo = $photos->store($vehicle, $file, $request->validated('alt_text'));
                $audit->record(
                    $this->admin($request),
                    $vehicle,
                    'vehicle.photo_uploaded',
                    null,
                    ['photo_id' => $photo->id, 'file_path' => $photo->file_path],
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );
            });
        } catch (\Throwable $exception) {
            if ($photo instanceof VehiclePhoto) {
                $photos->discardFiles($photo);
            }

            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Photo uploaded.']);

        return to_route('vehicles.edit', $vehicle);
    }

    public function arrange(
        ArrangeVehiclePhotosRequest $request,
        Vehicle $vehicle,
        VehiclePhotoService $photos,
        AdminAuditService $audit,
    ): RedirectResponse {
        $data = $request->validated();
        $old = $vehicle->photos()->get(['id', 'sort_order', 'is_primary'])->toArray();
        DB::transaction(function () use ($request, $vehicle, $photos, $audit, $data, $old): void {
            $photos->arrange($vehicle, $data['ordered_ids'], (int) $data['primary_id']);
            $audit->record(
                $this->admin($request),
                $vehicle,
                'vehicle.photos_arranged',
                ['photos' => $old],
                ['photos' => $vehicle->photos()->get(['id', 'sort_order', 'is_primary'])->toArray()],
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Photo order saved.']);

        return to_route('vehicles.edit', $vehicle);
    }

    public function destroy(
        Request $request,
        Vehicle $vehicle,
        VehiclePhoto $photo,
        VehiclePhotoService $photos,
        AdminAuditService $audit,
    ): RedirectResponse {
        $old = ['photo_id' => (int) $photo->id, 'file_path' => (string) $photo->file_path];

        DB::transaction(function () use ($request, $vehicle, $photo, $photos, $audit, $old): void {
            $photos->delete($vehicle, $photo);
            $audit->record(
                $this->admin($request),
                $vehicle,
                'vehicle.photo_deleted',
                $old,
                null,
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Photo deleted.']);

        return to_route('vehicles.edit', $vehicle);
    }
}
