<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\ValueObjects\RentalPeriod;
use DateTimeImmutable;

/** Server implementation of the customer's price-calculator.html algorithm. */
class PricingCalculator
{
    public const SEASONS = ['high', 'middle', 'low'];

    public const TIERS = ['1d', '7d', '14d', '21d', 'month'];

    public const DAYS = [1, 7, 14, 21, 30];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'algorithm' => 'calculator-v1',
            'rules' => ['step' => 50, 'mode' => 'linear', 'holdDays' => 2, 'cap' => true, 'monthly' => true, 'monthlyStart' => 28, 'avg' => true],
            'prefs' => [
                'linkMiddle' => false, 'linkLow' => false,
                'roundSteps' => array_fill_keys(self::SEASONS, array_fill(0, 5, 50)),
                'discounts' => ['middle' => array_fill(0, 5, 10), 'low' => array_fill(0, 5, 21)],
                'seasonCalendar' => ['high', 'high', 'high', 'middle', 'middle', 'low', 'low', 'low', 'low', 'middle', 'middle', 'high'],
            ],
            'dayOverrides' => [],
        ];
    }

    /** @param array<string, int> $prices
     * @param  array<string, mixed>  $settings
     */
    public function daily(int $days, array $prices, array $settings, string $season): int
    {
        $override = $settings['dayOverrides']["{$season}:{$days}"] ?? null;
        if ($override !== null) {
            return (int) $override;
        }

        $p = array_map(fn (string $tier): int => $prices[$tier], self::TIERS);
        $cfg = $settings['rules'];
        if ($days >= 30) {
            return $p[4];
        }
        $exact = array_search($days, self::DAYS, true);
        if ($exact !== false) {
            return $p[$exact];
        }

        $index = 1;
        while ($days > self::DAYS[$index]) {
            $index++;
        }
        [$left, $right, $p1, $p2] = [self::DAYS[$index - 1], self::DAYS[$index], $p[$index - 1], $p[$index]];
        $span = max(1, $right - $left);
        $elapsed = $days - $left;
        if ($cfg['mode'] === 'delayed') {
            $hold = min($cfg['holdDays'], $span - 1);
            $daily = $p1 / $left;
            $price = $elapsed <= $hold
                ? $p1 + $daily * $elapsed
                : $p1 + $daily * $hold + ($p2 - $p1 - $daily * $hold) * (($elapsed - $hold) / max(1, $span - $hold));
        } else {
            $price = $p1 + ($p2 - $p1) * ($elapsed / $span);
        }
        $step = $cfg['step'];
        // All values are nonnegative; PHP HalfUp is JavaScript Math.round here.
        $price = round($price / $step, 0, PHP_ROUND_HALF_UP) * $step;
        if ($cfg['cap']) {
            $price = min($price, $p2);
        }
        if ($cfg['monthly'] && $days >= $cfg['monthlyStart']) {
            $price = $p[4];
        } elseif ($cfg['avg'] && $price / $days > $p1 / $left) {
            $price = min($price, floor(($p1 / $left * $days) / $step) * $step);
        }

        return max(0, (int) $price);
    }

    /** @param array<string, array<string, int>> $prices
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function quote(array $prices, array $settings, ?RentalPeriod $period = null, int $days = 1, string $season = 'high'): array
    {
        $counts = array_fill_keys(self::SEASONS, 0);
        $tied = [];
        $months = 0;
        $remainder = $days;
        if ($period !== null) {
            $days = $period->totalDays();
            foreach ($period->dates() as $date) {
                $counts[$settings['prefs']['seasonCalendar'][(int) $date->format('n') - 1]]++;
            }
            $tied = array_values(array_filter(self::SEASONS, fn (string $key): bool => $counts[$key] === max($counts)));
            usort($tied, fn (string $a, string $b): int => $prices[$a]['month'] <=> $prices[$b]['month']);
            $season = $tied[0];
            while ($this->anniversary($period->startsOn, $months + 1) <= $period->endsOn) {
                $months++;
            }
            $remainder = (int) $this->anniversary($period->startsOn, $months)->diff($period->endsOn)->format('%a');
            // RentalPeriod intentionally preserves existing same-day-as-one-day semantics.
            if ($period->startsOn == $period->endsOn) {
                $remainder = 1;
            }
        } elseif ($days > 31) {
            $months = intdiv($days - 1, 31);
            $remainder = $days - $months * 31;
        }
        $monthOverride = $period === null ? 31 : 30;
        $monthRate = $settings['dayOverrides']["{$season}:{$monthOverride}"] ?? $prices[$season]['month'];
        $extra = $remainder > 0 ? $this->daily($remainder, $prices[$season], $settings, $season) : 0;
        $total = $settings['dayOverrides']["{$season}:{$days}"] ?? ($months * $monthRate + $extra);

        return [
            'algorithm' => 'calculator-v1', 'mode' => $period === null ? 'days-preview' : 'calendar',
            'days' => $days, 'season' => $season, 'season_days' => $counts, 'tied_seasons' => $tied,
            'completed_months' => $months, 'remainder_days' => $remainder,
            'monthly_rate' => $monthRate, 'remainder_total' => $extra, 'total' => (int) $total,
            'manual_total' => array_key_exists("{$season}:{$days}", $settings['dayOverrides']),
        ];
    }

    /** @param array<string, array<string, int>> $prices
     * @param  array<string, mixed>  $settings
     * @return array<string, list<int>>
     */
    public function matrix(array $prices, array $settings): array
    {
        $matrix = [];
        foreach (self::SEASONS as $season) {
            $matrix[$season] = array_map(fn (int $day): int => $this->daily($day, $prices[$season], $settings, $season), range(1, 31));
        }

        return $matrix;
    }

    private function anniversary(DateTimeImmutable $start, int $months): DateTimeImmutable
    {
        $first = $start->modify('first day of this month')->modify("+{$months} months");

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $start->format('j'), (int) $first->format('t')));
    }
}
