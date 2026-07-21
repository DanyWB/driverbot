<?php

namespace App\Domain\Vehicles\Enums;

enum VehicleType: string
{
    case Bike = 'bike';
    case Scooter = 'scooter';
    case Car = 'car';
}
