<?php

namespace App\Http\Requests\Bookings;

use App\Http\Requests\Concerns\ValidatesRentalTimeRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBookingDatesRequest extends FormRequest
{
    use ValidatesRentalTimeRange;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'return_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateRentalTimeRange(
            $validator,
            $this->input('starts_on'),
            $this->input('ends_on'),
            $this->input('pickup_time'),
            $this->input('return_time'),
            'return_time',
        )];
    }
}
