<?php

namespace App\Domain\Pricing\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class RentalPeriod
{
    public function __construct(
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
    ) {
        if ($endsOn < $startsOn) {
            throw new InvalidArgumentException('Rental end date cannot be before its start date.');
        }
    }

    public static function fromStrings(string $startsOn, string $endsOn, string $timezone = 'Asia/Bangkok'): self
    {
        return new self(
            self::parseDate($startsOn, $timezone),
            self::parseDate($endsOn, $timezone),
        );
    }

    public function totalDays(): int
    {
        return max(1, (int) $this->startsOn->diff($this->endsOn)->format('%a'));
    }

    /** @return iterable<DateTimeImmutable> */
    public function dates(): iterable
    {
        $date = $this->startsOn;
        for ($day = 0; $day < $this->totalDays(); $day++, $date = $date->modify('+1 day')) {
            yield $date;
        }
    }

    private static function parseDate(string $value, string $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($timezone));

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid rental date: {$value}");
        }

        return $date;
    }
}
