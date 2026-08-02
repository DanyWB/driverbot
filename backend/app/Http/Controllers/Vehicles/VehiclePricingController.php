<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\Services\PricingTemplateService;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\GenerateVehiclePricingRequest;
use App\Http\Requests\Vehicles\UpdateVehiclePricingRequest;
use App\Http\Requests\Vehicles\VehiclePricingQuoteRequest;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VehiclePricingController extends Controller
{
    use HandlesAdminContext;

    public function update(
        UpdateVehiclePricingRequest $request,
        Vehicle $vehicle,
        VehiclePricingService $pricing,
        AdminAuditService $audit,
    ): RedirectResponse {
        $oldMatrix = $pricing->matrix($vehicle);
        $oldTemplate = $vehicle->getRawOriginal('pricing_profile');
        $wasVisible = (bool) $vehicle->is_visible_for_booking;
        $data = $request->validated();

        DB::transaction(function () use ($request, $vehicle, $pricing, $audit, $data, $oldMatrix, $oldTemplate, $wasVisible): void {
            $updated = $pricing->replaceMatrix($vehicle, $data['prices'], $data['enabled']);

            if (is_string($data['template'] ?? null)) {
                $updated->forceFill(['pricing_profile' => $data['template']])->save();
                $updated->refresh();
            }

            $audit->record(
                $this->admin($request),
                $updated,
                'vehicle.pricing_updated',
                ['matrix' => $oldMatrix, 'pricing_template' => $oldTemplate, 'is_visible_for_booking' => $wasVisible],
                ['matrix' => $pricing->matrix($updated), 'pricing_template' => $updated->getRawOriginal('pricing_profile'), 'is_visible_for_booking' => (bool) $updated->is_visible_for_booking],
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );
        });

        $message = $wasVisible && ! $vehicle->refresh()->is_visible_for_booking
            ? 'Prices saved. Vehicle was hidden because the active matrix is incomplete.'
            : 'Prices saved.';
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('vehicles.edit', $vehicle);
    }

    public function quote(
        VehiclePricingQuoteRequest $request,
        Vehicle $vehicle,
        PricingService $pricing,
    ): JsonResponse {
        try {
            $quote = $pricing->quote(
                $vehicle,
                RentalPeriod::fromStrings(
                    (string) $request->validated('starts_on'),
                    (string) $request->validated('ends_on'),
                ),
                false,
            );
        } catch (PricingException $exception) {
            return response()->json([
                'error' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ], 422);
        }

        return response()->json(['data' => $quote->toArray()]);
    }

    public function generate(
        GenerateVehiclePricingRequest $request,
        Vehicle $vehicle,
        PricingTemplateService $templates,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'data' => $templates->generate(
                $vehicle->type,
                (string) $data['template'],
                $data['base_prices'],
            ),
        ]);
    }
}
