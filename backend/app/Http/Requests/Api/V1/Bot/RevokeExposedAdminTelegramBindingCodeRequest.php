<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;

class RevokeExposedAdminTelegramBindingCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'telegram_id' => trim((string) $this->header('X-Telegram-User-ID', '')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[23456789A-HJ-NP-Z]{4}-?[23456789A-HJ-NP-Z]{4}$/i'],
            'telegram_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,19}$/'],
        ];
    }
}
