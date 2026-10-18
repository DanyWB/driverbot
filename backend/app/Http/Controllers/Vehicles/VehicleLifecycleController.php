<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Vehicles\Services\VehicleLifecycleService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VehicleLifecycleController extends Controller
{
    use HandlesAdminContext;

    public function archive(Request $request, Vehicle $vehicle, VehicleLifecycleService $service): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $service->apply($vehicle, 'archive', $this->admin($request), requestId: $this->requestId($request));

        return to_route('vehicles.index', ['tab' => 'archive']);
    }

    public function restore(Request $request, Vehicle $vehicle, VehicleLifecycleService $service): RedirectResponse
    {
        $service->apply($vehicle, 'restore', $this->admin($request), requestId: $this->requestId($request));

        return to_route('vehicles.edit', $this->fleetEditParameters($request, $vehicle));
    }

    public function unpublish(Request $request, Vehicle $vehicle, VehicleLifecycleService $service): RedirectResponse
    {
        $service->apply($vehicle, 'unpublish', $this->admin($request), requestId: $this->requestId($request));

        return back();
    }

    public function destroy(Request $request, Vehicle $vehicle, VehicleLifecycleService $service): RedirectResponse
    {
        $data = $request->validate(['confirmation_name' => ['required', 'string', 'max:255']]);
        $service->apply($vehicle, 'delete', $this->admin($request), $data['confirmation_name'], $this->requestId($request));

        return to_route('vehicles.index', ['tab' => 'archive']);
    }
}
