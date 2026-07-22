<?php

namespace App\Http\Requests\Api\V1\Bot\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use Throwable;

trait ValidatesBotRentalPeriod
{
    private function validateBotRentalPeriod(
        Validator $validator,
        mixed $startsOn,
        mixed $endsOn,
        string $startsOnField,
        string $endsOnField,
    ): void {
        if (! is_string($startsOn) || ! is_string($endsOn)) {
            return;
        }

        try {
            $timezone = (string) config('business.timezone', 'Asia/Bangkok');
            $start = CarbonImmutable::parse($startsOn, $timezone)->startOfDay();
            $end = CarbonImmutable::parse($endsOn, $timezone)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($start->lt(CarbonImmutable::now($timezone)->startOfDay())) {
            $validator->errors()->add($startsOnField, 'The rental start date cannot be in the past.');
        }

        $maxDays = max(1, (int) config('bot_api.max_rental_days', 366));

        if ($end->gte($start) && ((int) $start->diffInDays($end)) + 1 > $maxDays) {
            $validator->errors()->add($endsOnField, "The rental period cannot exceed {$maxDays} days.");
        }
    }
}
