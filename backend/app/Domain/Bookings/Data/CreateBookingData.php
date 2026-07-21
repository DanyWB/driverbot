<?php

namespace App\Domain\Bookings\Data;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;

final readonly class CreateBookingData
{
    public function __construct(
        public int $customerId,
        public int $vehicleId,
        public string $startsOn,
        public string $endsOn,
        public BookingSource $source,
        public BookingStatus $initialStatus = BookingStatus::Pending,
        public ?string $pickupTime = null,
        public ?string $returnTime = null,
        public ?string $clientComment = null,
        public ?string $adminNote = null,
        public ?string $depositNote = null,
    ) {}
}
