<?php

namespace App\Http\Requests\Api\V1\Bot;

use App\Domain\Vehicles\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBotVehiclesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(VehicleType::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }
}
