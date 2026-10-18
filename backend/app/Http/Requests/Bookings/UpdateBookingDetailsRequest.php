<?php

namespace App\Http\Requests\Bookings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingDetailsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'expected_details_version' => ['required', 'integer', 'min:1'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:80'],
            'contact_telegram' => ['nullable', 'string', 'max:80'],
            'payments' => ['required', 'array:prepayment,deposit'],
        ];
        foreach (['prepayment', 'deposit'] as $payment) {
            $rules["payments.{$payment}"] = ['required', 'array:amount,currency,received'];
            $rules["payments.{$payment}.amount"] = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000', "required_if:payments.{$payment}.received,true"];
            $rules["payments.{$payment}.currency"] = ['required', Rule::in(['THB', 'USD'])];
            $rules["payments.{$payment}.received"] = ['required', 'boolean'];
        }

        return $rules;
    }
}
