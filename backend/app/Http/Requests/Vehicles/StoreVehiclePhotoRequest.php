<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreVehiclePhotoRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('business.vehicle_photo_max_mb') * 1024),
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
