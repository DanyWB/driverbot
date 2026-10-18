<?php

namespace App\Http\Requests\PricingPresets\Concerns;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;

trait ValidatesPricingPresetMatrix
{
    /** @return array<string, mixed> */
    protected function matrixRules(): array
    {
        $seasonKeys = array_column(PricingSeasonKey::cases(), 'value');
        $tierKeys = array_column(PricingTier::cases(), 'value');
        $rules = [
            'prices' => ['required', 'array:'.implode(',', $seasonKeys)],
        ];

        foreach (PricingSeasonKey::cases() as $season) {
            $rules["prices.{$season->value}"] = ['required', 'array:'.implode(',', $tierKeys)];

            foreach (PricingTier::cases() as $tier) {
                $rules["prices.{$season->value}.{$tier->value}"] = [
                    'bail',
                    'required',
                    'integer',
                    'min:1',
                    'max:100000000',
                ];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    protected function matrixMessages(): array
    {
        return [
            'prices.required' => __('pricing_presets.full_matrix', [], $this->adminLocale()),
            'prices.array' => __('pricing_presets.full_matrix', [], $this->adminLocale()),
            'prices.*.required' => __('pricing_presets.full_matrix', [], $this->adminLocale()),
            'prices.*.array' => __('pricing_presets.full_matrix', [], $this->adminLocale()),
            'prices.*.*.required' => __('pricing_presets.full_matrix', [], $this->adminLocale()),
            'prices.*.*.integer' => __('pricing_presets.price_integer', [], $this->adminLocale()),
            'prices.*.*.min' => __('pricing_presets.price_integer', [], $this->adminLocale()),
            'prices.*.*.max' => __('pricing_presets.price_too_large', [], $this->adminLocale()),
        ];
    }

    /** @return array<string, array<string, int>> */
    protected function validatedMatrix(): array
    {
        $matrix = [];

        foreach (PricingSeasonKey::cases() as $season) {
            foreach (PricingTier::cases() as $tier) {
                $matrix[$season->value][$tier->value] = (int) $this->validated(
                    "prices.{$season->value}.{$tier->value}",
                );
            }
        }

        return $matrix;
    }

    protected function adminLocale(): string
    {
        return $this->cookie('admin_locale') === 'ru' ? 'ru' : 'en';
    }
}
