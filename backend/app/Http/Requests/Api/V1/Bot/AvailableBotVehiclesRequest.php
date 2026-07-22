<?php

namespace App\Http\Requests\Api\V1\Bot;

class AvailableBotVehiclesRequest extends ListBotVehiclesRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
