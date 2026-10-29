<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Exceptions\PricingPresetConflictException;
use App\Models\PricingPreset;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PricingSettingsImport
{
    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function preview(array $payload): array
    {
        if (($payload['format'] ?? null) === 'drive-pricing-presets') {
            return $this->nativePreview($payload);
        }
        Validator::make($payload, [
            'format' => ['required', 'in:price-calculator-settings'],
            'version' => ['required', 'integer', 'in:1'],
            'presets' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'presets.*' => ['required', 'array:group,data,prefs,rules,dayOverrides,fleetId'],
            'fleetAssignments' => ['present', 'array', 'max:1000'],
            'fleetAssignments.*' => ['required', 'string', 'max:255'],
        ])->validate();
        $presets = [];
        foreach ($payload['presets'] as $source) {
            if (isset($source['fleetId'])) {
                throw ValidationException::withMessages(['payload' => 'Legacy fleet-specific presets must be converted before import.']);
            }
            $preset = app(PricingSettings::class)->importPreset($source);
            if (isset($presets[$preset['name']])) {
                throw ValidationException::withMessages(['payload' => 'Duplicate preset names are not allowed.']);
            }
            $presets[$preset['name']] = $preset;
        }
        foreach ($payload['fleetAssignments'] as $sourceId => $group) {
            if (! isset($presets[$group]) || strlen((string) $sourceId) > 255 || $sourceId === '') {
                throw ValidationException::withMessages(['payload' => 'Every assignment must reference a known preset and source ID.']);
            }
        }

        return ['presets' => $presets, 'assignments' => $payload['fleetAssignments']];
    }

    /** Commit only previewed data; revision snapshots come from the server-side preview.
     * @param  array<string,mixed>  $preview
     * @param  array<string,int|null>  $presetTargets
     * @param  array<string,int|null>  $mapping
     * @return array<string,mixed>
     */
    public function commit(array $preview, array $presetTargets, array $mapping, int $adminId, string $locale): array
    {
        $presetTargets = array_map(fn ($id): ?int => $id === null ? null : (int) $id, $presetTargets);
        $mapping = array_map(fn ($id): ?int => $id === null ? null : (int) $id, $mapping);
        if (array_diff(array_keys($preview['presets']), array_keys($presetTargets)) || array_diff(array_keys($presetTargets), array_keys($preview['presets']))) {
            throw ValidationException::withMessages(['preset_targets' => 'Choose create or an existing target for every preset.']);
        }
        if (array_diff(array_keys($preview['assignments']), array_keys($mapping)) || array_diff(array_keys($mapping), array_keys($preview['assignments']))) {
            throw ValidationException::withMessages(['mapping' => 'Explicitly map or skip every source vehicle.']);
        }
        $vehicleIds = array_values(array_filter($mapping, fn ($id): bool => $id !== null));
        $targetIds = array_values(array_filter($presetTargets, fn ($id): bool => $id !== null));
        if (count($vehicleIds) !== count(array_unique($vehicleIds)) || count($targetIds) !== count(array_unique($targetIds))) {
            throw ValidationException::withMessages(['mapping' => 'Duplicate destination vehicles or presets are not allowed.']);
        }
        foreach ($vehicleIds as $id) {
            if (! isset($preview['vehicle_revisions'][$id])) {
                throw ValidationException::withMessages(['mapping' => 'Unknown vehicle; refresh the preview.']);
            }
        }
        foreach ($targetIds as $id) {
            if (! isset($preview['preset_revisions'][$id])) {
                throw ValidationException::withMessages(['preset_targets' => 'Unknown preset; refresh the preview.']);
            }
        }

        return DB::transaction(function () use ($preview, $presetTargets, $mapping, $vehicleIds, $targetIds, $adminId, $locale): array {
            $sourcePresetIds = array_map(fn ($id) => $preview['vehicle_revisions'][$id]['preset_id'], $vehicleIds);
            $lockIds = array_unique(array_filter([...$targetIds, ...$sourcePresetIds], fn ($id): bool => $id !== null));
            sort($lockIds);
            PricingPreset::query()->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get();
            Vehicle::query()->whereIn('id', $vehicleIds)->orderBy('id')->lockForUpdate()->get();
            // Reject stale links before any nested service could acquire an unplanned
            // current-preset lock after already holding the vehicle (lock inversion).
            foreach ($vehicleIds as $vehicleId) {
                $vehicle = Vehicle::query()->findOrFail($vehicleId);
                $revision = $preview['vehicle_revisions'][$vehicleId];
                $currentPresetId = $vehicle->pricing_preset_id === null ? null : (int) $vehicle->pricing_preset_id;
                if ($currentPresetId !== $revision['preset_id'] || (int) $vehicle->pricing_version !== $revision['version']) {
                    throw new PricingPresetConflictException('vehicle_pricing_conflict', __('pricing_presets.vehicle_conflict', [], $locale), [
                        'current_pricing_preset_id' => $currentPresetId, 'current_vehicle_pricing_revision' => (int) $vehicle->pricing_version,
                    ]);
                }
            }
            $service = app(PricingPresetService::class);
            $saved = [];
            $position = 0;
            foreach ($preview['presets'] as $group => $data) {
                $data = app(PricingSettings::class)->storedPreset($data);
                $data['sort_order'] = $position++;
                $targetId = $presetTargets[$group];
                if ($targetId === null) {
                    if (PricingPreset::query()->where('name', $data['name'])->exists()) {
                        throw ValidationException::withMessages(['preset_targets' => "Preset '{$data['name']}' exists. Map its target explicitly."]);
                    }
                    $saved[$group] = $service->create($data, $adminId);
                } else {
                    $revision = $preview['preset_revisions'][$targetId];
                    $target = PricingPreset::query()->whereKey($targetId)->firstOrFail();
                    // Imported data does not guess scooter/car types. Preserve the target constraint.
                    $data['vehicle_type'] = $target->getRawOriginal('vehicle_type');
                    $saved[$group] = $service->update($target, [...$data, 'version' => $revision['version'], 'expected_linked_vehicles_count' => $revision['linked_count']], $adminId, $locale);
                }
            }
            $assigned = 0;
            $assignmentChanges = [];
            foreach ($mapping as $sourceId => $vehicleId) {
                if ($vehicleId === null) {
                    continue;
                }
                $revision = $preview['vehicle_revisions'][$vehicleId];
                // Updating an imported preset may have advanced this vehicle once already.
                $updatedOwnPreset = in_array($revision['preset_id'], $targetIds, true);
                $expectedVersion = $revision['version'] + ($updatedOwnPreset ? 1 : 0);
                $vehicle = Vehicle::query()->findOrFail($vehicleId);
                if ($vehicle->archived_at !== null) {
                    throw ValidationException::withMessages(['mapping' => 'Archived vehicles cannot be assigned by import.']);
                }
                $target = $saved[$preview['assignments'][$sourceId]];
                $service->attach($vehicle, $target, (int) $target->version, $revision['preset_id'], $expectedVersion, $locale);
                $assignmentChanges[] = ['vehicle' => $vehicle->refresh(), 'old_preset_id' => $revision['preset_id'], 'new_preset_id' => (int) $target->id];
                $assigned++;
            }

            foreach ($saved as $preset) {
                $preset->refresh()->load(['prices.season', 'vehicles:id,pricing_preset_id']);
            }

            return ['presets' => array_values($saved), 'assigned' => $assigned, 'skipped' => count($mapping) - $assigned, 'assignment_changes' => $assignmentChanges];
        });
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function nativePreview(array $payload): array
    {
        Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1'], 'presets' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'presets.*.id' => ['required', 'integer', 'distinct'], 'presets.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'presets.*.vehicle_type' => ['nullable', 'in:scooter,car'],
            'presets.*.prices' => ['required', 'array'], 'presets.*.calculation_settings' => ['present', 'nullable', 'array'],
            'assignments' => ['present', 'array', 'max:1000'], 'assignments.*.external_code' => ['required', 'string', 'max:255', 'distinct'],
            'assignments.*.pricing_preset_id' => ['required', 'integer'],
        ])->validate();
        $presets = [];
        $names = [];
        foreach ($payload['presets'] as $source) {
            // Reuse the strict customer schema for anchors; retain null legacy mode.
            $data = [];
            foreach ($source['prices'] as $season => $prices) {
                if (! is_array($prices) || array_diff(array_keys($prices), PricingCalculator::TIERS) || count($prices) !== 5) {
                    throw ValidationException::withMessages(['payload' => 'Invalid price matrix.']);
                }
                $data[$season] = array_map(fn ($tier) => $prices[$tier], PricingCalculator::TIERS);
            }
            $normalized = app(PricingSettings::class)->importPreset(['group' => $source['name'], 'data' => $data]);
            $normalized['calculation_settings'] = $source['calculation_settings'] === null ? null : app(PricingSettings::class)->validate($source['calculation_settings']);
            $normalized['vehicle_type'] = $source['vehicle_type'] ?? null;
            $presets[$source['name']] = $normalized;
            $names[$source['id']] = $source['name'];
        }
        $assignments = [];
        foreach ($payload['assignments'] as $row) {
            if (! isset($names[$row['pricing_preset_id']])) {
                throw ValidationException::withMessages(['payload' => 'Unknown assignment preset.']);
            }
            $assignments[$row['external_code']] = $names[$row['pricing_preset_id']];
        }

        return ['presets' => $presets, 'assignments' => $assignments];
    }
}
