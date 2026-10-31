<?php

namespace App\Http\Requests\Website;

use App\Domain\Website\WebsitePickupDate;
use App\Domain\Website\WebsiteValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'contact_channel' => ['required', Rule::in(['telegram_username', 'whatsapp', 'phone', 'email'])],
            'contact_value' => ['required', 'string', 'max:190', ...match ($this->input('contact_channel')) {
                'email' => ['email:rfc'],
                'telegram_username' => ['regex:/^@?[a-zA-Z][a-zA-Z0-9_]{4,31}$/'],
                'phone', 'whatsapp' => ['regex:/^\+?[0-9][0-9 ()-]{5,24}$/'],
                default => [],
            }],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WebsitePickupDate::earliest()->format('Y-m-d')],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on', 'before_or_equal:'.now('Asia/Bangkok')->addYears(2)->format('Y-m-d')],
            'terms_accepted' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', Rule::in([(string) config('website.terms_version')])],
            'client_comment' => ['nullable', 'string', 'max:2000'],
            'company' => ['nullable', 'string', 'max:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'contact_value', 'client_comment'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return WebsiteValidation::messages((string) $this->route('locale'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return WebsiteValidation::attributes((string) $this->route('locale'));
    }
}
