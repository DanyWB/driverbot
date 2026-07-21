<?php

namespace App\Http\Requests\Bookings;

use Illuminate\Foundation\Http\FormRequest;

class OverrideBookingPriceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'manual_total' => ['required', 'integer', 'min:0', 'max:99999999'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
