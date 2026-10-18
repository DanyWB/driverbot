<?php

namespace App\Http\Requests\PricingPresets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyPricingPresetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('pricing_presets', 'name')],
            'source_version' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $locale = $this->cookie('admin_locale') === 'ru' ? 'ru' : 'en';

        return [
            'name.required' => __('pricing_presets.name_required', [], $locale),
            'name.string' => __('pricing_presets.name_string', [], $locale),
            'name.max' => __('pricing_presets.name_max', [], $locale),
            'name.unique' => __('pricing_presets.name_unique', [], $locale),
            'source_version.required' => __('pricing_presets.version_invalid', [], $locale),
            'source_version.integer' => __('pricing_presets.version_invalid', [], $locale),
            'source_version.min' => __('pricing_presets.version_invalid', [], $locale),
        ];
    }
}
