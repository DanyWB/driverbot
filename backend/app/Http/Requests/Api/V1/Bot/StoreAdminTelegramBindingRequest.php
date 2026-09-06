<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminTelegramBindingRequest extends FormRequest
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
            'chat_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,19}$/', 'same:telegram_id'],
            'chat_type' => ['required', 'string', Rule::in(['private'])],
            'username' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]+$/'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:35'],
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     telegram_id: string,
     *     chat_id: string,
     *     chat_type: string,
     *     username: string|null,
     *     first_name: string|null,
     *     last_name: string|null,
     *     locale: string|null
     * }
     */
    public function bindingData(): array
    {
        return [
            'code' => (string) $this->validated('code'),
            'telegram_id' => (string) $this->validated('telegram_id'),
            'chat_id' => (string) $this->validated('chat_id'),
            'chat_type' => (string) $this->validated('chat_type'),
            'username' => $this->nullableValidatedString('username'),
            'first_name' => $this->nullableValidatedString('first_name'),
            'last_name' => $this->nullableValidatedString('last_name'),
            'locale' => $this->nullableValidatedString('locale'),
        ];
    }

    private function nullableValidatedString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
