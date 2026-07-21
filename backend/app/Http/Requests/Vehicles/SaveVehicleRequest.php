<?php

namespace App\Http\Requests\Vehicles;

use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $vehicle = $this->route('vehicle');
        $vehicleId = $vehicle instanceof Vehicle ? $vehicle->id : null;

        return [
            'external_code' => ['nullable', 'string', 'max:160', Rule::unique('vehicles', 'external_code')->ignore($vehicleId)],
            'type' => ['required', Rule::enum(VehicleType::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'inventory_code' => ['nullable', 'string', 'max:100', Rule::unique('vehicles', 'inventory_code')->ignore($vehicleId)],
            'year' => ['nullable', 'integer', 'between:1900,2200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'characteristics_text' => ['nullable', 'string', 'max:10000'],
            'emoji' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
            'is_visible_for_booking' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'pricing_profile' => ['nullable', 'string', 'max:64'],
        ];
    }
}
