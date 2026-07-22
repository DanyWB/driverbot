<?php

namespace App\Http\Requests\Api\V1\Bot;

use App\Http\Requests\Api\V1\Bot\Concerns\ValidatesBotRentalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BotQuoteRequest extends FormRequest
{
    use ValidatesBotRentalPeriod;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBotRentalPeriod(
            $validator,
            $this->input('starts_on'),
            $this->input('ends_on'),
            'starts_on',
            'ends_on',
        )];
    }
}
