<?php

namespace App\Http\Requests\Vehicles;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateVehiclePricingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $templates = config('pricing_templates.templates', []);

        return [
            'template' => ['required', 'string', Rule::in(is_array($templates) ? array_keys($templates) : [])],
            'base_prices' => ['required', 'array'],
            'base_prices.high' => ['required', 'integer', 'min:100', 'max:10000000'],
            'base_prices.middle' => ['required', 'integer', 'min:100', 'max:10000000'],
            'base_prices.low' => ['required', 'integer', 'min:100', 'max:10000000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $vehicle = $this->route('vehicle');
            $template = config('pricing_templates.templates.'.(string) $this->input('template'));

            if (! $vehicle instanceof Vehicle || ! is_array($template)) {
                return;
            }

            if (($template['vehicle_type'] ?? null) !== $vehicle->getRawOriginal('type')) {
                $validator->errors()->add('template', 'The selected pricing template does not match the vehicle type.');
            }
        }];
    }
}
