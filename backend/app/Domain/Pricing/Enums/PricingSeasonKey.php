<?php

namespace App\Domain\Pricing\Enums;

enum PricingSeasonKey: string
{
    case High = 'high';
    case Middle = 'middle';
    case Low = 'low';
}
