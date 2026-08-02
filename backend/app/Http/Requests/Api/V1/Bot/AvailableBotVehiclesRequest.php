<?php

namespace App\Http\Requests\Api\V1\Bot;

use App\Http\Requests\Api\V1\Bot\Concerns\ValidatesBotRentalPeriod;
use Illuminate\Validation\Validator;

class AvailableBotVehiclesRequest extends ListBotVehiclesRequest
{
    use ValidatesBotRentalPeriod;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBotRentalPeriod(
            $validator,
            $this->input('start_date'),
            $this->input('end_date'),
            'start_date',
            'end_date',
        )];
    }
}
