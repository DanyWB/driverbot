<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBotBookingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['current', 'history', 'all'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'offset' => ['nullable', 'integer', 'between:0,10000'],
        ];
    }
}
