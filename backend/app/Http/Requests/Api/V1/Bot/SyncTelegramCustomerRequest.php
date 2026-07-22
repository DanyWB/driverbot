<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;

class SyncTelegramCustomerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'telegram_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,19}$/'],
            'username' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]+$/'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:35'],
        ];
    }
}
