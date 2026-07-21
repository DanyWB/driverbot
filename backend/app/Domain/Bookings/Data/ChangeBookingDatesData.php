<?php

namespace App\Domain\Bookings\Data;

final readonly class ChangeBookingDatesData
{
    public function __construct(
        public string $startsOn,
        public string $endsOn,
        public ?string $pickupTime = null,
        public ?string $returnTime = null,
    ) {}
}
