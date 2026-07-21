<?php

namespace App\Http\Requests\Bookings;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Vehicles\Enums\VehicleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBookingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', Rule::in(['active', 'all', 'archive'])],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'source' => ['nullable', Rule::enum(BookingSource::class)],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'starts_from' => ['nullable', 'date_format:Y-m-d'],
            'starts_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_from'],
            'documents' => ['nullable', Rule::in(['yes', 'no'])],
            'sort' => ['nullable', Rule::in(['created_at', 'updated_at', 'starts_on', 'ends_on', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([15, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'search' => (string) $this->validated('search', ''),
            'scope' => (string) $this->validated('scope', 'active'),
            'status' => (string) $this->validated('status', ''),
            'source' => (string) $this->validated('source', ''),
            'vehicle_id' => $this->validated('vehicle_id'),
            'vehicle_type' => (string) $this->validated('vehicle_type', ''),
            'starts_from' => (string) $this->validated('starts_from', ''),
            'starts_to' => (string) $this->validated('starts_to', ''),
            'documents' => (string) $this->validated('documents', ''),
            'sort' => (string) $this->validated('sort', 'created_at'),
            'direction' => (string) $this->validated('direction', 'desc'),
            'per_page' => (int) $this->validated('per_page', 25),
        ];
    }
}
