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
        return (int) $this->startsOn->diff($this->endsOn)->format('%a') + 1;
    }

    /** @return iterable<DateTimeImmutable> */
    public function dates(): iterable
    {
        for ($date = $this->startsOn; $date <= $this->endsOn; $date = $date->modify('+1 day')) {
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
