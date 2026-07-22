<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;

class CancelBotBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
