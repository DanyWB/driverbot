<?php

namespace App\Http\Requests\Bookings;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_mode' => ['required', Rule::in(['existing', 'new'])],
            'customer_id' => ['nullable', 'required_if:customer_mode,existing', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'required_if:customer_mode,new', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'telegram_username' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]{5,32}$/'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'return_time' => ['nullable', 'date_format:H:i'],
            'source' => ['required', Rule::in([
                BookingSource::AdminPhone->value,
                BookingSource::AdminWhatsApp->value,
                BookingSource::AdminInstagram->value,
                BookingSource::AdminManual->value,
                BookingSource::Telegram->value,
            ])],
            'initial_status' => ['required', Rule::in([BookingStatus::Pending->value, BookingStatus::Approved->value])],
            'client_comment' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
            'deposit_note' => ['nullable', 'string', 'max:2000'],
            'manual_total' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'override_reason' => ['nullable', 'required_with:manual_total', 'string', 'max:1000'],
        ];
    }
}
