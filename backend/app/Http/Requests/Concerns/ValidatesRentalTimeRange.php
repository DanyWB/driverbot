<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use Throwable;

trait ValidatesRentalTimeRange
{
    private function validateRentalTimeRange(
        Validator $validator,
        mixed $startsOn,
        mixed $endsOn,
        mixed $pickupTime,
        mixed $returnTime,
        string $returnTimeField,
    ): void {
        if (! is_string($startsOn)
            || ! is_string($endsOn)
            || ! is_string($pickupTime)
            || ! is_string($returnTime)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $pickupTime) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $returnTime) !== 1) {
            return;
        }

        try {
            $timezone = (string) config('business.timezone', 'Asia/Bangkok');
            $startsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$startsOn} {$pickupTime}", $timezone);
            $endsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$endsOn} {$returnTime}", $timezone);
        } catch (Throwable) {
            return;
        }

        if (! $startsAt instanceof CarbonImmutable || ! $endsAt instanceof CarbonImmutable) {
            return;
        }

        if ($endsAt->lt($startsAt->addHour())) {
            $validator->errors()->add($returnTimeField, 'Rental return must be at least one hour after pickup.');
        }
    }
}
