<?php

namespace App\Domain\Pricing\Enums;

enum PricingSource: string
{
    case Automatic = 'automatic';
    case ManualOverride = 'manual_override';
}
