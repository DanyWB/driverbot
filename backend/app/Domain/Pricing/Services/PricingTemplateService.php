<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Vehicles\Enums\VehicleType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class PricingTemplateService
{
    /** @return list<array{key: string, label: string}> */
    public function options(VehicleType|string $vehicleType): array
    {
        $type = $vehicleType instanceof VehicleType ? $vehicleType->value : $vehicleType;
        $options = [];

        foreach ($this->templates() as $key => $template) {
            if (($template['vehicle_type'] ?? null) !== $type) {
                continue;
            }

            $options[] = [
                'key' => $key,
                'label' => (string) $template['label'],
            ];
        }

        return $options;
    }

    /**
     * @param  array<string, int|string>  $basePrices
     * @return array{prices: array<string, array<string, int>>, enabled: array<string, array<string, bool>>}
     */
    public function generate(VehicleType|string $vehicleType, string $templateKey, array $basePrices): array
    {
        $type = $vehicleType instanceof VehicleType ? $vehicleType->value : $vehicleType;
        $template = $this->templates()[$templateKey] ?? null;

        if (! is_array($template) || ($template['vehicle_type'] ?? null) !== $type) {
            throw new InvalidArgumentException('The selected pricing template does not match the vehicle type.');
        }

        $coefficients = $template['coefficients'] ?? null;

        if (! is_array($coefficients)) {
            throw new InvalidArgumentException('The selected pricing template is not configured.');
        }

        $step = max(1, (int) config('pricing_templates.rounding_step', 100));
        $prices = [];
        $enabled = [];

        foreach (PricingSeasonKey::cases() as $season) {
            $basePrice = (int) ($basePrices[$season->value] ?? 0);

            if ($basePrice < $step) {
                throw new InvalidArgumentException("Base price for {$season->value} season must be at least {$step}.");
            }

            foreach (PricingTier::cases() as $tier) {
                $coefficient = $coefficients[$tier->value] ?? null;

                if (! is_string($coefficient)) {
                    throw new InvalidArgumentException("Coefficient for {$tier->value} is not configured.");
                }

                if ($tier === PricingTier::OneDay) {
                    $prices[$season->value][$tier->value] = $basePrice;
                } else {
                    $rawTotal = BigDecimal::of($basePrice)
                        ->multipliedBy($tier->anchorDays())
                        ->multipliedBy($coefficient);
                    $prices[$season->value][$tier->value] = $rawTotal
                        ->dividedBy($step, 0, RoundingMode::Floor)
                        ->multipliedBy($step)
                        ->toInt();
                }

                $enabled[$season->value][$tier->value] = true;
            }
        }

        return ['prices' => $prices, 'enabled' => $enabled];
    }

    /** @return array<string, array<string, mixed>> */
    private function templates(): array
    {
        $templates = config('pricing_templates.templates', []);

        return is_array($templates) ? $templates : [];
    }
}
