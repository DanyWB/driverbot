<?php

namespace App\Http\Requests\PricingPresets;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Http\Requests\PricingPresets\Concerns\ValidatesPricingPresetMatrix;
use App\Models\PricingPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingPresetRequest extends FormRequest
{
    use ValidatesPricingPresetMatrix;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $preset = $this->route('pricingPreset');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pricing_presets', 'name')->ignore($preset instanceof PricingPreset ? $preset->id : null),
            ],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'version' => ['required', 'integer', 'min:1'],
            'expected_linked_vehicles_count' => ['required', 'integer', 'min:0'],
            ...$this->matrixRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->matrixMessages(),
            'name.required' => __('pricing_presets.name_required', [], $this->adminLocale()),
            'name.string' => __('pricing_presets.name_string', [], $this->adminLocale()),
            'name.max' => __('pricing_presets.name_max', [], $this->adminLocale()),
            'name.unique' => __('pricing_presets.name_unique', [], $this->adminLocale()),
            'vehicle_type.enum' => __('pricing_presets.vehicle_type_invalid', [], $this->adminLocale()),
            'version.required' => __('pricing_presets.version_invalid', [], $this->adminLocale()),
            'version.integer' => __('pricing_presets.version_invalid', [], $this->adminLocale()),
            'version.min' => __('pricing_presets.version_invalid', [], $this->adminLocale()),
            'expected_linked_vehicles_count.required' => __('pricing_presets.links_count_invalid', [], $this->adminLocale()),
            'expected_linked_vehicles_count.integer' => __('pricing_presets.links_count_invalid', [], $this->adminLocale()),
            'expected_linked_vehicles_count.min' => __('pricing_presets.links_count_invalid', [], $this->adminLocale()),
        ];
    }

    /** @return array{name: string, vehicle_type: string|null, version: int, expected_linked_vehicles_count: int, prices: array<string, array<string, int>>,calculation_settings?:array<string,mixed>|null} */
    public function presetData(): array
    {
        $vehicleType = $this->validated('vehicle_type');

        $data = [
            'name' => (string) $this->validated('name'),
            'vehicle_type' => is_string($vehicleType) ? $vehicleType : null,
            'version' => (int) $this->validated('version'),
            'expected_linked_vehicles_count' => (int) $this->validated('expected_linked_vehicles_count'),
            'prices' => $this->validatedMatrix(),
        ];
        if ($this->has('calculation_settings')) {
            $data['calculation_settings'] = $this->validatedCalculationSettings();
        }

        return $data;
    }
}
