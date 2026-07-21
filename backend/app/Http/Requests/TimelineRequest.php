<?php

namespace App\Http\Requests;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Vehicles\Enums\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TimelineRequest extends FormRequest
{
    private const MAX_RANGE_DAYS = 93;

    protected function prepareForValidation(): void
    {
        $timezone = (string) config('business.timezone', 'Asia/Bangkok');
        $today = CarbonImmutable::now($timezone);
        $startsOn = $this->input('starts_on');
        $endsOn = $this->input('ends_on');

        if (! is_string($startsOn) || trim($startsOn) === '') {
            $startsOn = is_string($endsOn) && $endsOn !== ''
                ? $endsOn
                : $today->startOfMonth()->toDateString();
        }

        if (! is_string($endsOn) || trim($endsOn) === '') {
            $endsOn = $this->has('starts_on')
                ? $startsOn
                : $today->endOfMonth()->toDateString();
        }

        $this->merge([
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'visibility' => ['nullable', Rule::in(['active', 'visible', 'hidden', 'inactive', 'all'])],
            'status' => ['nullable', Rule::in([
                BookingStatus::Pending->value,
                BookingStatus::Approved->value,
                BookingStatus::Active->value,
                'maintenance',
            ])],
            'available_only' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['starts_on', 'ends_on'])) {
                return;
            }

            $timezone = (string) config('business.timezone', 'Asia/Bangkok');
            $start = CarbonImmutable::parse((string) $this->input('starts_on'), $timezone);
            $end = CarbonImmutable::parse((string) $this->input('ends_on'), $timezone);

            if (((int) $start->diffInDays($end)) + 1 > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('ends_on', 'The timeline range cannot exceed 93 days.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'starts_on' => (string) $this->validated('starts_on'),
            'ends_on' => (string) $this->validated('ends_on'),
            'vehicle_type' => (string) $this->validated('vehicle_type', ''),
            'category_id' => $this->validated('category_id'),
            'vehicle_id' => $this->validated('vehicle_id'),
            'visibility' => (string) $this->validated('visibility', 'active'),
            'status' => (string) $this->validated('status', ''),
            'available_only' => $this->boolean('available_only'),
        ];
    }
}
