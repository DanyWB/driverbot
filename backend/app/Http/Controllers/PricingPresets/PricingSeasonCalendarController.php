<?php

namespace App\Http\Controllers\PricingPresets;

use App\Domain\Pricing\Presenters\PricingPresetPresenter;
use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingSeasonCalendarService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\PricingPreset;
use App\Models\PricingSeason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PricingSeasonCalendarController extends Controller
{
    use HandlesAdminContext;

    public function show(PricingSeasonCalendarService $calendar): JsonResponse
    {
        return response()->json(['data' => $calendar->current()]);
    }

    public function update(
        Request $request,
        PricingSeasonCalendarService $calendar,
        PricingPresetPresenter $presenter,
        AdminAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'calendar' => ['required', 'array', 'list', 'size:12'],
            'calendar.*' => ['required', 'string', Rule::in(PricingCalculator::SEASONS)],
        ]);

        $result = DB::transaction(function () use ($request, $data, $calendar, $audit): array {
            $result = $calendar->apply($data['calendar'], (int) $this->admin($request)->id);
            $subject = PricingSeason::query()->orderBy('sort_order')->firstOrFail();
            $audit->record(
                $this->admin($request),
                $subject,
                'pricing_season_calendar.updated',
                ['calendar' => $result['previous']],
                ['calendar' => $result['calendar'], 'presets' => count($result['presets']), 'vehicles' => $result['vehicles']],
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );

            return $result;
        });

        $presets = PricingPreset::query()
            ->with(['prices.season', 'vehicles:id,pricing_preset_id'])
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (PricingPreset $preset): array => $presenter->present($preset))
            ->values()
            ->all();

        return response()->json([
            'data' => $result['calendar'],
            'presets' => $presets,
            'updated_presets' => count($result['presets']),
            'updated_vehicles' => $result['vehicles'],
        ]);
    }
}
