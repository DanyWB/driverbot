<?php

namespace App\Http\Controllers\PricingPresets;

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Presenters\PricingPresetPresenter;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\PricingPresets\CopyPricingPresetRequest;
use App\Http\Requests\PricingPresets\StorePricingPresetRequest;
use App\Http\Requests\PricingPresets\UpdatePricingPresetRequest;
use App\Models\PricingPreset;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PricingPresetController extends Controller
{
    use HandlesAdminContext;

    public function index(Request $request, PricingPresetPresenter $presenter): Response|JsonResponse
    {
        $presets = PricingPreset::query()
            ->with(['prices.season', 'vehicles:id,pricing_preset_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (PricingPreset $preset): array => $presenter->present($preset))
            ->values()
            ->all();

        if ($request->expectsJson()) {
            return response()->json(['data' => $presets]);
        }

        return Inertia::render('pricing-presets/Index', ['presets' => $presets]);
    }

    public function store(
        StorePricingPresetRequest $request,
        PricingPresetService $service,
        PricingPresetPresenter $presenter,
        AdminAuditService $audit,
    ): JsonResponse {
        try {
            $preset = DB::transaction(function () use ($request, $service, $presenter, $audit): PricingPreset {
                $preset = $service->create($request->presetData(), (int) $this->admin($request)->id);
                $audit->record(
                    $this->admin($request),
                    $preset,
                    'pricing_preset.created',
                    null,
                    $presenter->present($preset),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $preset;
            });
        } catch (UniqueConstraintViolationException) {
            $this->throwNameConflict($request);
        }

        return response()->json(['data' => $presenter->present($preset)], 201);
    }

    public function copy(
        CopyPricingPresetRequest $request,
        PricingPreset $pricingPreset,
        PricingPresetService $service,
        PricingPresetPresenter $presenter,
        AdminAuditService $audit,
    ): JsonResponse {
        try {
            $preset = DB::transaction(function () use ($request, $pricingPreset, $service, $presenter, $audit): PricingPreset {
                $preset = $service->copy(
                    $pricingPreset,
                    (string) $request->validated('name'),
                    (int) $this->admin($request)->id,
                    $request->has('source_version') ? (int) $request->validated('source_version') : null,
                    $this->locale($request),
                );
                $audit->record(
                    $this->admin($request),
                    $preset,
                    'pricing_preset.copied',
                    ['source_pricing_preset_id' => (int) $pricingPreset->id],
                    $presenter->present($preset),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $preset;
            });
        } catch (PricingPresetConflictException $exception) {
            return $this->conflict($exception);
        } catch (UniqueConstraintViolationException) {
            $this->throwNameConflict($request);
        }

        return response()->json(['data' => $presenter->present($preset)], 201);
    }

    public function update(
        UpdatePricingPresetRequest $request,
        PricingPreset $pricingPreset,
        PricingPresetService $service,
        PricingPresetPresenter $presenter,
        AdminAuditService $audit,
    ): JsonResponse {
        try {
            $preset = DB::transaction(function () use ($request, $pricingPreset, $service, $presenter, $audit): PricingPreset {
                $lockedPreset = PricingPreset::query()
                    ->whereKey($pricingPreset->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $oldValues = $presenter->present($lockedPreset);
                $preset = $service->update(
                    $lockedPreset,
                    $request->presetData(),
                    (int) $this->admin($request)->id,
                    $this->locale($request),
                );
                $audit->record(
                    $this->admin($request),
                    $preset,
                    'pricing_preset.updated',
                    $oldValues,
                    $presenter->present($preset),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $preset;
            });
        } catch (PricingPresetConflictException $exception) {
            return $this->conflict($exception);
        } catch (UniqueConstraintViolationException) {
            $this->throwNameConflict($request);
        }

        return response()->json(['data' => $presenter->present($preset)]);
    }

    private function conflict(PricingPresetConflictException $exception): JsonResponse
    {
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

    private function throwNameConflict(Request $request): never
    {
        throw ValidationException::withMessages([
            'name' => __('pricing_presets.name_unique', [], $this->locale($request)),
        ]);
    }
}
