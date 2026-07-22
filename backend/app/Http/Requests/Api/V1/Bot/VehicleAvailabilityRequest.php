<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class VehicleAvailabilityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $start = $this->date('start_date');
            $end = $this->date('end_date');

            if ($start !== null && $end !== null && $start->diffInDays($end) > 92) {
                $validator->errors()->add('end_date', 'Availability range cannot exceed 93 days.');
            }
        }];
    }
}
