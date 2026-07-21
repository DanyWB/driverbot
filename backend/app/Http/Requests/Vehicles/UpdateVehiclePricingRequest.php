<?php

namespace App\Http\Requests\Vehicles;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiclePricingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'prices' => ['required', 'array'],
            'enabled' => ['required', 'array'],
        ];

        foreach (PricingSeasonKey::cases() as $season) {
            $rules["prices.{$season->value}"] = ['required', 'array'];
            $rules["enabled.{$season->value}"] = ['required', 'array'];

            foreach (PricingTier::cases() as $tier) {
                $rules["prices.{$season->value}.{$tier->value}"] = ['nullable', 'integer', 'min:1', 'max:100000000'];
                $rules["enabled.{$season->value}.{$tier->value}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }
}
