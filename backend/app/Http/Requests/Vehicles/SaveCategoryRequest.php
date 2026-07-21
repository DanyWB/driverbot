<?php

namespace App\Http\Requests\Vehicles;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : null;

        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('categories', 'code')->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
