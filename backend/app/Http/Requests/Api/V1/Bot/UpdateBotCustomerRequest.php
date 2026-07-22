<?php

namespace App\Http\Requests\Api\V1\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBotCustomerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'locale' => ['sometimes', 'required', Rule::in(['ru', 'en', 'ua'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'username' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]+$/'],
            'passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = ['name', 'locale', 'phone', 'username', 'passport_number'];

            if (! collect($fields)->contains(fn (string $field): bool => $this->exists($field))) {
                $validator->errors()->add('profile', 'At least one profile field is required.');
            }
        }];
    }
}
