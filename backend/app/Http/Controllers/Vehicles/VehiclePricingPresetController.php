<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\AssignVehiclePricingPresetRequest;
use App\Http\Requests\Vehicles\DetachVehiclePricingPresetRequest;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VehiclePricingPresetController extends Controller
{
    use HandlesAdminContext;

    public function attach(
        AssignVehiclePricingPresetRequest $request,
        Vehicle $vehicle,
        PricingPresetService $service,
        VehiclePricingService $vehiclePricing,
        AdminAuditService $audit,
    ): RedirectResponse|JsonResponse {
        $data = $request->validated();
        $target = PricingPreset::query()->findOrFail((int) $data['preset_id']);
        $oldMatrix = $vehiclePricing->matrix($vehicle);

        try {
            DB::transaction(function () use ($request, $vehicle, $target, $service, $vehiclePricing, $audit, $data, $oldMatrix): void {
                $updated = $service->attach(
                    $vehicle,
                    $target,
                    (int) $data['preset_version'],
                    isset($data['expected_pricing_preset_id']) ? (int) $data['expected_pricing_preset_id'] : null,
                    (int) $data['expected_vehicle_pricing_revision'],
                    $this->locale($request),
                );
                $audit->record(
                    $this->admin($request),
                    $updated,
                    'vehicle.pricing_preset_attached',
                    [
                        'pricing_preset_id' => $data['expected_pricing_preset_id'] ?? null,
                        'pricing_version' => (int) $data['expected_vehicle_pricing_revision'],
                        'matrix' => $oldMatrix,
                    ],
                    [
                        'pricing_preset_id' => (int) $updated->pricing_preset_id,
                        'pricing_preset_version' => (int) $target->version,
                        'pricing_version' => (int) $updated->pricing_version,
                        'matrix' => $vehiclePricing->matrix($updated),
                    ],
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );
            });
        } catch (PricingPresetConflictException $exception) {
            return $this->conflict($request, $exception, 'preset_id');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('pricing_presets.attached', [], $this->locale($request))]);

        return to_route('vehicles.edit', $this->fleetEditParameters($request, $vehicle));
    }

    public function detach(
        DetachVehiclePricingPresetRequest $request,
        Vehicle $vehicle,
        PricingPresetService $service,
        AdminAuditService $audit,
    ): RedirectResponse|JsonResponse {
        $data = $request->validated();

        try {
            DB::transaction(function () use ($request, $vehicle, $service, $audit, $data): void {
                $updated = $service->detach(
                    $vehicle,
                    (int) $data['preset_version'],
                    (int) $data['expected_pricing_preset_id'],
                    (int) $data['expected_vehicle_pricing_revision'],
                    $this->locale($request),
                );
                $audit->record(
                    $this->admin($request),
                    $updated,
                    'vehicle.pricing_preset_detached',
                    [
                        'pricing_preset_id' => (int) $data['expected_pricing_preset_id'],
                        'pricing_version' => (int) $data['expected_vehicle_pricing_revision'],
                    ],
                    [
                        'pricing_preset_id' => null,
                        'pricing_version' => (int) $updated->pricing_version,
                    ],
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );
            });
        } catch (PricingPresetConflictException $exception) {
            return $this->conflict($request, $exception, 'preset_version');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('pricing_presets.detached', [], $this->locale($request))]);

        return to_route('vehicles.edit', $this->fleetEditParameters($request, $vehicle));
    }

    private function conflict(
        Request $request,
        PricingPresetConflictException $exception,
        string $field,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            return back()->withErrors([$field => $exception->getMessage()])->withInput();
        }

        return response()->json([
            'error' => $exception->errorCode,
            'message' => $exception->getMessage(),
            ...$exception->context,
        ], 409);
    }

    private function locale(Request $request): string
    {
        return $request->cookie('admin_locale') === 'ru' ? 'ru' : 'en';
    }
}
