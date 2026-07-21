<?php

namespace App\Http\Requests\Vehicles;

use App\Domain\Vehicles\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVehiclesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(VehicleType::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'state' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'visibility' => ['nullable', Rule::in(['all', 'visible', 'hidden'])],
            'pricing' => ['nullable', Rule::in(['all', 'complete', 'incomplete'])],
            'per_page' => ['nullable', Rule::in([15, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => (string) $this->validated('search', ''),
            'type' => (string) $this->validated('type', ''),
            'category_id' => $this->validated('category_id'),
            'state' => (string) $this->validated('state', 'all'),
            'visibility' => (string) $this->validated('visibility', 'all'),
            'pricing' => (string) $this->validated('pricing', 'all'),
            'per_page' => (int) $this->validated('per_page', 25),
        ];
    }
}
