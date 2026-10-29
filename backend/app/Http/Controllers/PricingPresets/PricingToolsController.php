<?php

namespace App\Http\Controllers\PricingPresets;

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Domain\Pricing\Presenters\PricingPresetPresenter;
use App\Domain\Pricing\Services\PricingCalculator;
use App\Domain\Pricing\Services\PricingPresetService;
use App\Domain\Pricing\Services\PricingSettings;
use App\Domain\Pricing\Services\PricingSettingsImport;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PricingToolsController extends Controller
{
    use HandlesAdminContext;

    public function calculate(Request $request, PricingPreset $pricingPreset, PricingPresetService $service, PricingCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['nullable', 'required_with:ends_on', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after:starts_on'],
            'days' => ['required_without:starts_on', 'integer', 'min:1', 'max:365'],
            'season' => ['required_without:starts_on', Rule::in(PricingCalculator::SEASONS)],
        ]);
        $period = isset($data['starts_on'], $data['ends_on']) ? RentalPeriod::fromStrings($data['starts_on'], $data['ends_on']) : null;
        if ($period !== null && $period->totalDays() > 365) {
            throw ValidationException::withMessages(['ends_on' => 'Preview is limited to 365 days.']);
        }
        $snapshot = DB::transaction(function () use ($pricingPreset, $service): array {
            // Preset writers lock this parent before replacing prices. Reload
            // under the same lock boundary, not the stale route-bound model.
            $preset = PricingPreset::query()->whereKey($pricingPreset->getKey())->sharedLock()->firstOrFail();
            if ($preset->calculation_settings === null) {
                throw ValidationException::withMessages(['preset' => 'This preset still uses legacy calculation. Enable calculator rules explicitly before using this preview.']);
            }

            return ['prices' => $service->matrix($preset), 'settings' => $preset->calculation_settings, 'version' => (int) $preset->version];
        });

        return response()->json(['data' => $calculator->quote($snapshot['prices'], $snapshot['settings'], $period, (int) ($data['days'] ?? 1), $data['season'] ?? 'high'), 'matrix' => $calculator->matrix($snapshot['prices'], $snapshot['settings']), 'version' => $snapshot['version']]);
    }

    public function fleet(VehiclePricingService $pricing): JsonResponse
    {
        $vehicles = Vehicle::query()->whereNull('archived_at')->with(['category', 'pricingPreset'])->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $vehicles->map(fn (Vehicle $vehicle): array => [
            'id' => (int) $vehicle->id, 'external_code' => $vehicle->external_code, 'name' => $vehicle->name,
            'inventory_code' => $vehicle->inventory_code, 'year' => $vehicle->year, 'type' => $vehicle->getRawOriginal('type'),
            'category' => $vehicle->category?->name, 'is_visible_for_booking' => (bool) $vehicle->is_visible_for_booking,
            'pricing_preset_id' => $vehicle->pricing_preset_id, 'preset_name' => $vehicle->pricingPreset?->name,
            'pricing_revision' => (int) $vehicle->pricing_version, 'algorithm' => $vehicle->calculation_settings === null ? 'legacy' : 'calculator-v1',
            'prices' => $pricing->matrix($vehicle),
        ])->all()]);
    }

    public function assign(Request $request, PricingPresetService $service, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['accepted'], 'preset_id' => ['required', 'integer', 'exists:pricing_presets,id'],
            'preset_version' => ['required', 'integer', 'min:1'],
            'vehicles' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'vehicles.*.id' => ['required', 'integer', 'distinct', 'exists:vehicles,id'],
            'vehicles.*.expected_preset_id' => ['present', 'nullable', 'integer'],
            'vehicles.*.expected_revision' => ['required', 'integer', 'min:1'],
        ]);
        try {
            DB::transaction(function () use ($data, $request, $service, $audit): void {
                $presetIds = array_unique(array_filter([$data['preset_id'], ...array_column($data['vehicles'], 'expected_preset_id')], fn ($id): bool => $id !== null));
                sort($presetIds);
                PricingPreset::query()->whereIn('id', $presetIds)->orderBy('id')->lockForUpdate()->get();
                Vehicle::query()->whereIn('id', array_column($data['vehicles'], 'id'))->orderBy('id')->lockForUpdate()->get();
                $target = PricingPreset::query()->whereKey($data['preset_id'])->firstOrFail();
                foreach ($data['vehicles'] as $row) {
                    $current = Vehicle::query()->whereKey($row['id'])->firstOrFail();
                    $currentPresetId = $current->pricing_preset_id === null ? null : (int) $current->pricing_preset_id;
                    $expectedPresetId = $row['expected_preset_id'] === null ? null : (int) $row['expected_preset_id'];
                    if ($currentPresetId !== $expectedPresetId || (int) $current->pricing_version !== (int) $row['expected_revision']) {
                        throw new PricingPresetConflictException('vehicle_pricing_conflict', 'Vehicle pricing changed. Refresh before assigning.', ['current_pricing_preset_id' => $currentPresetId, 'current_vehicle_pricing_revision' => (int) $current->pricing_version]);
                    }
                }
                foreach ($data['vehicles'] as $row) {
                    $vehicle = Vehicle::query()->whereKey($row['id'])->firstOrFail();
                    if ($vehicle->archived_at !== null) {
                        throw ValidationException::withMessages(['vehicles' => 'Cannot assign archived vehicles.']);
                    }
                    $updated = $service->attach($vehicle, $target, (int) $data['preset_version'], $row['expected_preset_id'] === null ? null : (int) $row['expected_preset_id'], (int) $row['expected_revision'], $request->cookie('admin_locale') === 'ru' ? 'ru' : 'en');
                    $audit->record($this->admin($request), $updated, 'vehicle.pricing_preset_assigned', ['preset_id' => $row['expected_preset_id']], ['preset_id' => (int) $target->id], $this->requestId($request), $request->ip(), $this->userAgent($request));
                }
            });
        } catch (PricingPresetConflictException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage(), ...$e->context], 409);
        }

        return response()->json(['assigned' => count($data['vehicles'])]);
    }

    public function bulkUpdate(Request $request, PricingPresetService $service, PricingPresetPresenter $presenter, AdminAuditService $audit): JsonResponse
    {
        $rules = [
            'confirmed' => ['accepted'], 'presets' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'presets.*.id' => ['required', 'integer', 'distinct', 'exists:pricing_presets,id'],
            'presets.*.version' => ['required', 'integer', 'min:1'],
            'presets.*.expected_linked_vehicles_count' => ['required', 'integer', 'min:0'],
            'presets.*.prices' => ['required', 'array:high,middle,low'],
            'presets.*.calculation_settings' => ['present', 'nullable', 'array'],
        ];
        foreach (PricingCalculator::SEASONS as $season) {
            $rules["presets.*.prices.{$season}"] = ['required', 'array:1d,7d,14d,21d,month'];
            foreach (PricingCalculator::TIERS as $tier) {
                $rules["presets.*.prices.{$season}.{$tier}"] = ['required', 'integer', 'min:1', 'max:100000000'];
            }
        }
        $data = $request->validate($rules);
        try {
            $result = DB::transaction(function () use ($data, $service, $presenter, $audit, $request): array {
                $ids = array_column($data['presets'], 'id');
                $locked = PricingPreset::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                Vehicle::query()->whereIn('pricing_preset_id', $ids)->orderBy('id')->lockForUpdate()->get();
                $result = [];
                foreach ($data['presets'] as $row) {
                    $preset = $locked->get($row['id']);
                    if (! $preset instanceof PricingPreset) {
                        abort(404);
                    }
                    $old = $presenter->present($preset);
                    $saved = $service->update($preset, [
                        'name' => (string) $preset->name, 'vehicle_type' => $preset->getRawOriginal('vehicle_type'),
                        'version' => (int) $row['version'], 'expected_linked_vehicles_count' => (int) $row['expected_linked_vehicles_count'],
                        'prices' => app(PricingSettings::class)->validatePrices($row['prices']),
                        'calculation_settings' => $row['calculation_settings'] === null ? null : app(PricingSettings::class)->validate($row['calculation_settings']),
                    ], (int) $this->admin($request)->id, $request->cookie('admin_locale') === 'ru' ? 'ru' : 'en');
                    $result[] = $presenter->present($saved);
                    $audit->record($this->admin($request), $saved, 'pricing_preset.bulk_updated', $old, $presenter->present($saved), $this->requestId($request), $request->ip(), $this->userAgent($request));
                }

                return $result;
            });
        } catch (PricingPresetConflictException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage(), ...$e->context], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function preview(Request $request, PricingSettingsImport $import): JsonResponse
    {
        $request->validate(['payload' => ['required', 'array']]);
        if (strlen(json_encode($request->input('payload'), JSON_THROW_ON_ERROR)) > 2000000) {
            throw ValidationException::withMessages(['payload' => 'Settings file must not exceed 2 MB.']);
        }
        $preview = $import->preview($request->input('payload'));
        $presets = PricingPreset::query()->withCount('vehicles')->get();
        $vehicles = Vehicle::query()->whereNull('archived_at')->get();
        $preview['preset_revisions'] = $presets->mapWithKeys(fn ($preset): array => [$preset->id => ['version' => (int) $preset->version, 'linked_count' => (int) $preset->vehicles_count]])->all();
        $preview['vehicle_revisions'] = $vehicles->mapWithKeys(fn ($vehicle): array => [$vehicle->id => ['version' => (int) $vehicle->pricing_version, 'preset_id' => $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id]])->all();
        $preview['expires_at'] = now()->addMinutes(20)->timestamp;
        $preview['admin_id'] = (int) $this->admin($request)->id;
        $token = (string) Str::uuid();
        $request->session()->put('pricing_import_preview', ['token' => $token, 'data' => $preview]);

        return response()->json(['token' => $token, 'presets' => array_values($preview['presets']), 'assignments' => $preview['assignments'], 'existing_presets' => $presets->map(fn ($p): array => ['id' => (int) $p->id, 'name' => $p->name, 'version' => (int) $p->version, 'linked_count' => (int) $p->vehicles_count])->all(), 'vehicles' => $vehicles->map(fn ($v): array => ['id' => (int) $v->id, 'name' => $v->name, 'external_code' => $v->external_code])->all()]);
    }

    public function commit(Request $request, PricingSettingsImport $import, AdminAuditService $audit, PricingPresetPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            'preset_targets' => ['present', 'array', 'max:100'], 'preset_targets.*' => ['nullable', 'integer', 'min:1'],
            'mapping' => ['present', 'array', 'max:1000'], 'mapping.*' => ['nullable', 'integer', 'min:1'],
        ]);
        $stored = $request->session()->get('pricing_import_preview');
        if (! is_array($stored) || ! hash_equals($stored['token'], $data['token']) || $stored['data']['expires_at'] < now()->timestamp || $stored['data']['admin_id'] !== (int) $this->admin($request)->id) {
            throw ValidationException::withMessages(['token' => 'Import preview expired. Preview the file again.']);
        }
        try {
            $result = DB::transaction(function () use ($stored, $data, $request, $import, $audit, $presenter): array {
                $result = $import->commit($stored['data'], $data['preset_targets'], $data['mapping'], (int) $this->admin($request)->id, $request->cookie('admin_locale') === 'ru' ? 'ru' : 'en');
                foreach ($result['presets'] as $preset) {
                    $audit->record($this->admin($request), $preset, 'pricing_preset.imported', null, $presenter->present($preset), $this->requestId($request), $request->ip(), $this->userAgent($request));
                }
                foreach ($result['assignment_changes'] as $change) {
                    $audit->record($this->admin($request), $change['vehicle'], 'vehicle.pricing_preset_imported', ['preset_id' => $change['old_preset_id']], ['preset_id' => $change['new_preset_id']], $this->requestId($request), $request->ip(), $this->userAgent($request));
                }

                return $result;
            });
        } catch (PricingPresetConflictException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage(), ...$e->context], 409);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['preset_targets' => 'A preset name was taken after preview. Refresh the preview.']);
        }
        $request->session()->forget('pricing_import_preview');

        return response()->json(['data' => array_map(fn ($preset): array => $presenter->present($preset), $result['presets']), 'assigned' => $result['assigned'], 'skipped' => $result['skipped']]);
    }

    public function export(PricingPresetService $service): StreamedResponse
    {
        $presets = PricingPreset::query()->with('prices.season')->orderBy('sort_order')->orderBy('name')->get();
        $export = $presets->map(function (PricingPreset $preset) use ($service): array {
            $settings = $preset->calculation_settings;

            // Legacy presets are exported explicitly, not silently converted to calculator-v1.
            return ['id' => (int) $preset->id, 'name' => $preset->name, 'vehicle_type' => $preset->getRawOriginal('vehicle_type'), 'version' => (int) $preset->version, 'prices' => $service->matrix($preset), 'calculation_settings' => $settings];
        })->all();

        return response()->streamDownload(function () use ($export): void {
            echo json_encode(['format' => 'drive-pricing-presets', 'version' => 1, 'presets' => $export, 'assignments' => Vehicle::query()->whereNotNull('pricing_preset_id')->get(['id', 'external_code', 'pricing_preset_id', 'pricing_version'])->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, 'drive-pricing-presets.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    public function reorder(Request $request, PricingPresetPresenter $presenter, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate(['confirmed' => ['accepted'], 'presets' => ['required', 'array', 'list', 'min:1', 'max:500'], 'presets.*.id' => ['required', 'integer', 'distinct'], 'presets.*.version' => ['required', 'integer', 'min:1']]);
        try {
            $result = DB::transaction(function () use ($request, $data, $presenter, $audit): array {
                $locked = PricingPreset::query()->whereIn('id', array_column($data['presets'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $result = [];
                foreach ($data['presets'] as $index => $row) {
                    $preset = $locked->get($row['id']);
                    if (! $preset instanceof PricingPreset || (int) $preset->version !== (int) $row['version']) {
                        throw new PricingPresetConflictException('pricing_preset_version_conflict', 'Preset order changed. Refresh before reordering.');
                    }
                    $previousOrder = (int) $preset->sort_order;
                    $preset->forceFill(['sort_order' => $index, 'version' => (int) $preset->version + 1, 'updated_by_admin_id' => $this->admin($request)->id])->save();
                    $audit->record($this->admin($request), $preset, 'pricing_preset.reordered', ['sort_order' => $previousOrder], ['sort_order' => $index], $this->requestId($request), $request->ip(), $this->userAgent($request));
                    $result[] = $presenter->present($preset);
                }

                return $result;
            });
        } catch (PricingPresetConflictException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function destroy(Request $request, PricingPreset $pricingPreset, PricingPresetPresenter $presenter, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate(['confirmed' => ['accepted'], 'version' => ['required', 'integer', 'min:1']]);
        try {
            DB::transaction(function () use ($request, $data, $pricingPreset, $presenter, $audit): void {
                $preset = PricingPreset::query()->whereKey($pricingPreset->id)->lockForUpdate()->firstOrFail();
                if ((int) $preset->version !== (int) $data['version']) {
                    throw new PricingPresetConflictException('pricing_preset_version_conflict', 'Preset changed. Refresh before deleting.');
                }
                if ($preset->vehicles()->exists()) {
                    throw ValidationException::withMessages(['preset' => 'Detach every linked vehicle before deleting this preset.']);
                }
                $audit->record($this->admin($request), $preset, 'pricing_preset.deleted', $presenter->present($preset), null, $this->requestId($request), $request->ip(), $this->userAgent($request));
                $preset->delete();
            });
        } catch (PricingPresetConflictException $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['deleted' => (int) $pricingPreset->id]);
    }
}
