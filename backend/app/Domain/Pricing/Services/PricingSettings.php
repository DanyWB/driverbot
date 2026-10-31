<?php

namespace App\Domain\Pricing\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingSettings
{
    public const SOURCE_SEASONS = ['Высокий сезон' => 'high', 'Средний сезон' => 'middle', 'Низкий сезон' => 'low'];

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function validate(array $settings): array
    {
        Validator::make($settings, ['rules' => ['required', 'array'], 'prefs' => ['required', 'array'], 'dayOverrides' => ['present', 'array']])->validate();
        $rules = [
            'algorithm' => ['required', Rule::in(['calculator-v1'])],
            'rules' => ['required', 'array:step,mode,holdDays,cap,monthly,monthlyStart,avg'],
            'rules.step' => ['required', 'integer', 'min:1', 'max:100000'],
            'rules.mode' => ['required', Rule::in(['linear', 'delayed'])],
            'rules.holdDays' => ['required', 'integer', 'min:0', 'max:30'],
            'rules.monthlyStart' => ['required', 'integer', 'min:1', 'max:30'],
            'prefs' => ['required', 'array:linkMiddle,linkLow,roundSteps,discounts,seasonCalendar'],
            'prefs.roundSteps' => ['required', 'array:high,middle,low'],
            'prefs.discounts' => ['required', 'array:middle,low'],
            'prefs.seasonCalendar' => ['required', 'array', 'list', 'size:12'],
            'prefs.seasonCalendar.*' => ['required', 'string', Rule::in(PricingCalculator::SEASONS)],
            'dayOverrides' => ['present', 'array', 'max:1095'],
        ];
        foreach (['cap', 'monthly', 'avg'] as $key) {
            $rules["rules.{$key}"] = ['required', 'boolean'];
        }
        foreach (['linkMiddle', 'linkLow'] as $key) {
            $rules["prefs.{$key}"] = ['required', 'boolean'];
        }
        foreach (PricingCalculator::SEASONS as $season) {
            $rules["prefs.roundSteps.{$season}"] = ['required', 'array', 'list', 'size:5'];
            $rules["prefs.roundSteps.{$season}.*"] = ['required', 'integer', 'min:1', 'max:100000'];
        }
        foreach (['middle', 'low'] as $season) {
            $rules["prefs.discounts.{$season}"] = ['required', 'array', 'list', 'size:5'];
            $rules["prefs.discounts.{$season}.*"] = ['required', 'numeric', 'min:0', 'max:100'];
        }
        foreach ($settings['dayOverrides'] ?? [] as $key => $amount) {
            $rules["dayOverrides.{$key}"] = ['integer', 'min:0', 'max:100000000'];
        }
        $validator = Validator::make($settings, $rules);
        $validator->after(function ($validator) use ($settings): void {
            foreach (array_keys($settings) as $key) {
                if (! in_array($key, ['algorithm', 'rules', 'prefs', 'dayOverrides'], true)) {
                    $validator->errors()->add($key, 'Unknown calculation setting.');
                }
            }
            foreach (array_keys($settings['dayOverrides'] ?? []) as $key) {
                if (! preg_match('/^(high|middle|low):([1-9]|[1-9][0-9]|[12][0-9]{2}|3[0-5][0-9]|36[0-5])$/D', (string) $key)) {
                    $validator->errors()->add('dayOverrides', 'Override keys must use season:day (1–365).');
                }
            }
        });
        $data = $validator->validate();
        foreach (['step', 'holdDays', 'monthlyStart'] as $key) {
            $data['rules'][$key] = (int) $data['rules'][$key];
        }
        foreach (['cap', 'monthly', 'avg'] as $key) {
            $data['rules'][$key] = (bool) $data['rules'][$key];
        }
        foreach (['linkMiddle', 'linkLow'] as $key) {
            $data['prefs'][$key] = (bool) $data['prefs'][$key];
        }
        foreach (PricingCalculator::SEASONS as $season) {
            $data['prefs']['roundSteps'][$season] = array_map('intval', $data['prefs']['roundSteps'][$season]);
        }
        $data['dayOverrides'] = array_map('intval', $data['dayOverrides']);

        return $data;
    }

    /** @param array<string, mixed> $source
     * @return array{name:string,prices:array<string,array<string,int>>,vehicle_type:null,calculation_settings:array<string,mixed>}
     */
    public function importPreset(array $source): array
    {
        Validator::make($source, ['prefs' => ['sometimes', 'array'], 'rules' => ['sometimes', 'array'], 'data' => ['required', 'array'], 'dayOverrides' => ['sometimes', 'array']])->validate();
        $defaults = PricingCalculator::defaults();
        $settings = ['algorithm' => 'calculator-v1', 'rules' => $source['rules'] ?? $defaults['rules'], 'prefs' => $source['prefs'] ?? [], 'dayOverrides' => $source['dayOverrides'] ?? []];
        $settings['prefs'] = array_replace($defaults['prefs'], $settings['prefs']);
        Validator::make($settings, ['prefs.roundSteps' => ['required', 'array'], 'prefs.seasonCalendar' => ['required', 'array', 'list', 'size:12'], 'prefs.seasonCalendar.*' => ['required', 'string'], 'dayOverrides' => ['present', 'array']])->validate();
        foreach (['roundSteps'] as $key) {
            $settings['prefs'][$key] = $this->seasons($settings['prefs'][$key]);
        }
        $settings['prefs']['seasonCalendar'] = array_map(fn ($key) => self::SOURCE_SEASONS[$key] ?? $key, $settings['prefs']['seasonCalendar']);
        $normalizedOverrides = [];
        foreach ($settings['dayOverrides'] as $key => $value) {
            if (! preg_match('/^(high|middle|low|Высокий сезон|Средний сезон|Низкий сезон):([1-9][0-9]{0,2})$/uD', (string) $key, $parts)) {
                throw ValidationException::withMessages(['dayOverrides' => 'Invalid override key.']);
            }
            $normalizedKey = (self::SOURCE_SEASONS[$parts[1]] ?? $parts[1]).':'.$parts[2];
            if (array_key_exists($normalizedKey, $normalizedOverrides)) {
                throw ValidationException::withMessages(['dayOverrides' => 'Duplicate normalized override key.']);
            }
            $normalizedOverrides[$normalizedKey] = $value;
        }
        $settings['dayOverrides'] = $normalizedOverrides;
        $matrix = [];
        foreach ($this->seasons($source['data'] ?? []) as $season => $amounts) {
            if (! is_array($amounts) || ! array_is_list($amounts) || count($amounts) !== 5) {
                throw ValidationException::withMessages(['payload' => 'Every season needs five package totals.']);
            }
            $matrix[$season] = array_combine(PricingCalculator::TIERS, $amounts);
        }
        $matrixRules = ['name' => ['required', 'string', 'max:255'], 'prices' => ['required', 'array:high,middle,low']];
        foreach (PricingCalculator::SEASONS as $season) {
            $matrixRules["prices.{$season}"] = ['required', 'array:1d,7d,14d,21d,month'];
            foreach (PricingCalculator::TIERS as $tier) {
                $matrixRules["prices.{$season}.{$tier}"] = ['required', 'integer', 'min:1', 'max:100000000'];
            }
        }
        $data = Validator::make(['name' => $source['group'] ?? null, 'prices' => $matrix], $matrixRules)->validate();

        return ['name' => (string) $data['name'], 'prices' => $this->validatePrices($data['prices']), 'vehicle_type' => null, 'calculation_settings' => $this->validate($settings)];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function seasons(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[self::SOURCE_SEASONS[$key] ?? $key] = $value;
        }

        return $result;
    }

    /** @param array<string,array<string,int>> $prices
     * @param  array<string,mixed>|null  $settings
     * @return array<string,array<string,int>>
     */
    public function linkedPrices(array $prices, ?array $settings): array
    {
        if ($settings === null) {
            return $prices;
        }
        foreach (['middle' => 'linkMiddle', 'low' => 'linkLow'] as $season => $flag) {
            if (! $settings['prefs'][$flag]) {
                continue;
            }
            foreach (PricingCalculator::TIERS as $index => $tier) {
                $step = $settings['prefs']['roundSteps'][$season][$index];
                $scaled = $prices['high'][$tier] * (1 - $settings['prefs']['discounts'][$season][$index] / 100) / $step;
                $lower = floor($scaled);
                // Match the customer's Math.round for nonnegative IEEE-754 values.
                // PHP round compensates near decimal ties: 150.49999999999997 -> 151,
                // while JavaScript keeps this just-below-half value at 150.
                $amount = (int) (($scaled - $lower < 0.5 ? $lower : $lower + 1) * $step);
                if ($amount < 1 || $amount > 100000000) {
                    throw ValidationException::withMessages(['calculation_settings' => 'Linked discounts must produce positive package totals within 100,000,000 THB.']);
                }
                $prices[$season][$tier] = $amount;
            }
        }

        return $prices;
    }

    /** @param array<string,mixed> $prices
     * @return array<string,array<string,int>>
     */
    public function validatePrices(array $prices): array
    {
        $rules = ['prices' => ['required', 'array:high,middle,low']];
        foreach (PricingCalculator::SEASONS as $season) {
            $rules["prices.{$season}"] = ['required', 'array:1d,7d,14d,21d,month'];
            foreach (PricingCalculator::TIERS as $tier) {
                $rules["prices.{$season}.{$tier}"] = ['required', 'integer', 'min:1', 'max:100000000'];
            }
        }
        $data = Validator::make(['prices' => $prices], $rules)->validate();
        $result = [];
        foreach (PricingCalculator::SEASONS as $season) {
            foreach (PricingCalculator::TIERS as $tier) {
                $result[$season][$tier] = (int) $data['prices'][$season][$tier];
            }
        }

        return $result;
    }

    /** Normalize a validated server-stored import record before persistence.
     * @param  array<string,mixed>  $data
     * @return array{name:string,vehicle_type:string|null,prices:array<string,array<string,int>>,calculation_settings:array<string,mixed>|null}
     */
    public function storedPreset(array $data): array
    {
        $validated = Validator::make($data, ['name' => ['required', 'string', 'max:255'], 'vehicle_type' => ['nullable', 'in:scooter,car'], 'prices' => ['required', 'array'], 'calculation_settings' => ['present', 'nullable', 'array']])->validate();

        return ['name' => (string) $validated['name'], 'vehicle_type' => is_string($validated['vehicle_type'] ?? null) ? $validated['vehicle_type'] : null, 'prices' => $this->validatePrices($validated['prices']), 'calculation_settings' => $validated['calculation_settings'] === null ? null : $this->validate($validated['calculation_settings'])];
    }
}
