<?php

namespace App\Http\Requests\PricingPresets;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Http\Requests\PricingPresets\Concerns\ValidatesPricingPresetMatrix;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingPresetRequest extends FormRequest
{
    use ValidatesPricingPresetMatrix;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('pricing_presets', 'name')],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
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
        ];
    }

    /** @return array{name: string, vehicle_type: string|null, prices: array<string, array<string, int>>,calculation_settings?:array<string,mixed>|null} */
    public function presetData(): array
    {
        $vehicleType = $this->validated('vehicle_type');

        $data = [
            'name' => (string) $this->validated('name'),
            'vehicle_type' => is_string($vehicleType) ? $vehicleType : null,
            'prices' => $this->validatedMatrix(),
        ];
        if ($this->has('calculation_settings')) {
            $data['calculation_settings'] = $this->validatedCalculationSettings();
        }

        return $data;
    }
}
