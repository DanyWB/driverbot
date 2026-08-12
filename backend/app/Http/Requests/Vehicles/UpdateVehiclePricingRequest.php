<?php

namespace App\Http\Requests\Vehicles;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateVehiclePricingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $templates = config('pricing_templates.templates', []);
        $rules = [
            'prices' => ['required', 'array'],
            'enabled' => ['required', 'array'],
            'template' => ['nullable', 'string', Rule::in(is_array($templates) ? array_keys($templates) : [])],
        ];

        foreach (PricingSeasonKey::cases() as $season) {
            $rules["prices.{$season->value}"] = ['required', 'array'];
            $rules["enabled.{$season->value}"] = ['required', 'array'];

            foreach (PricingTier::cases() as $tier) {
                $rules["prices.{$season->value}.{$tier->value}"] = [
                    'bail',
                    'nullable',
                    'integer',
                    'min:1',
                    'multiple_of:50',
                    'max:100000000',
                ];
                $rules["enabled.{$season->value}.{$tier->value}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $message = $this->pricingStepMessage();

        return [
            'prices.*.*.integer' => $message,
            'prices.*.*.min' => $message,
            'prices.*.*.multiple_of' => $message,
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $vehicle = $this->route('vehicle');
            $templateKey = $this->input('template');
            $template = is_string($templateKey)
                ? config('pricing_templates.templates.'.$templateKey)
                : null;

            if (
                $templateKey !== null
                && $vehicle instanceof Vehicle
                && is_array($template)
                && ($template['vehicle_type'] ?? null) !== $vehicle->getRawOriginal('type')
            ) {
                $validator->errors()->add('template', 'The selected pricing template does not match the vehicle type.');
            }

            foreach (PricingSeasonKey::cases() as $season) {
                foreach (PricingTier::cases() as $tier) {
                    $enabled = $this->input("enabled.{$season->value}.{$tier->value}");
                    $price = $this->input("prices.{$season->value}.{$tier->value}");

                    if (in_array($enabled, [true, 1, '1'], true) && ($price === null || $price === '')) {
                        $validator->errors()->add(
                            "prices.{$season->value}.{$tier->value}",
                            'An enabled tariff requires a package total.',
                        );
                    }
                }
            }
        }];
    }

    private function pricingStepMessage(): string
    {
        return $this->cookie('admin_locale') === 'ru'
            ? 'Цена должна быть положительным целым числом и кратна 50 THB.'
            : 'Price must be a positive whole number divisible by 50 THB.';
    }
}
