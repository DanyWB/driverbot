<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

class DetachVehiclePricingPresetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset_version' => ['required', 'integer', 'min:1'],
            'expected_pricing_preset_id' => ['required', 'integer', 'exists:pricing_presets,id'],
            'expected_vehicle_pricing_revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $locale = $this->cookie('admin_locale') === 'ru' ? 'ru' : 'en';

        return [
            'preset_version.required' => __('pricing_presets.version_invalid', [], $locale),
            'preset_version.integer' => __('pricing_presets.version_invalid', [], $locale),
            'preset_version.min' => __('pricing_presets.version_invalid', [], $locale),
            'expected_pricing_preset_id.required' => __('pricing_presets.expected_preset_invalid', [], $locale),
            'expected_pricing_preset_id.integer' => __('pricing_presets.expected_preset_invalid', [], $locale),
            'expected_pricing_preset_id.exists' => __('pricing_presets.expected_preset_invalid', [], $locale),
            'expected_vehicle_pricing_revision.required' => __('pricing_presets.revision_invalid', [], $locale),
            'expected_vehicle_pricing_revision.integer' => __('pricing_presets.revision_invalid', [], $locale),
            'expected_vehicle_pricing_revision.min' => __('pricing_presets.revision_invalid', [], $locale),
        ];
    }
}
