<?php

namespace App\Domain\Pricing\Enums;

use InvalidArgumentException;

enum PricingTier: string
{
    case OneDay = '1d';
    case SevenDays = '7d';
    case FourteenDays = '14d';
    case TwentyOneDays = '21d';
    case Month = 'month';

    public static function forDuration(int $days): self
    {
        return match (true) {
            $days < 1 => throw new InvalidArgumentException('Rental duration must be at least one day.'),
            $days <= 6 => self::OneDay,
            $days <= 13 => self::SevenDays,
            $days <= 20 => self::FourteenDays,
            $days <= 29 => self::TwentyOneDays,
            default => self::Month,
        };
    }

    public function minimumDays(): int
    {
        return match ($this) {
            self::OneDay => 1,
            self::SevenDays => 7,
            self::FourteenDays => 14,
            self::TwentyOneDays => 21,
            self::Month => 30,
        };
    }

    public function maximumDays(): ?int
    {
        return match ($this) {
            self::OneDay => 6,
            self::SevenDays => 13,
            self::FourteenDays => 20,
            self::TwentyOneDays => 29,
            self::Month => null,
        };
    }

    public function anchorDays(): int
    {
        return match ($this) {
            self::OneDay => 1,
            self::SevenDays => 7,
            self::FourteenDays => 14,
            self::TwentyOneDays => 21,
            self::Month => 30,
        };
    }
}
