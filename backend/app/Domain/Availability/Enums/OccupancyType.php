<?php

namespace App\Domain\Availability\Enums;

enum OccupancyType: string
{
    case Booking = 'booking';
    case Maintenance = 'maintenance';
}
