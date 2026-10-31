<?php

namespace App\Domain\Website;

use Carbon\CarbonImmutable;

/** Website requests retain the existing fixed 12:00 Bangkok pickup model. */
class WebsitePickupDate
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Bangkok')->startOfDay();
    }

    public static function earliest(): CarbonImmutable
    {
        $now = CarbonImmutable::now('Asia/Bangkok');
        $today = $now->startOfDay();

        return $now->greaterThanOrEqualTo($today->setTime(12, 0)) ? $today->addDay() : $today;
    }
}
