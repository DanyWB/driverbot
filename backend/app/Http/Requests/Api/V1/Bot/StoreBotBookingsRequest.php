<?php

namespace App\Http\Requests\Api\V1\Bot;

use App\Http\Requests\Api\V1\Bot\Concerns\ValidatesBotRentalPeriod;
use App\Http\Requests\Concerns\ValidatesRentalTimeRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBotBookingsRequest extends FormRequest
{
    use ValidatesBotRentalPeriod;
    use ValidatesRentalTimeRange;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = max(1, (int) config('bot_api.max_batch_size', 10));

        return [
            'terms_accepted' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', "max:{$max}"],
            'items.*.client_reference' => ['required', 'string', 'max:100', 'distinct'],
            'items.*.vehicle_id' => ['required', 'integer', 'distinct', 'exists:vehicles,id'],
            'items.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'items.*.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:items.*.starts_on'],
            'items.*.pickup_time' => ['nullable', 'date_format:H:i'],
            'items.*.return_time' => ['nullable', 'date_format:H:i'],
            'items.*.helmets_quantity' => ['required', 'integer', 'between:0,4'],
            'items.*.delivery_required' => ['required', 'boolean'],
            'items.*.delivery_address' => ['nullable', 'string', 'max:2000'],
            'items.*.client_comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (in_array($item['delivery_required'] ?? false, [true, 1, '1'], true)
                    && trim((string) ($item['delivery_address'] ?? '')) === '') {
                    $validator->errors()->add(
                        "items.{$index}.delivery_address",
                        'A delivery address is required when delivery is requested.',
                    );
                }

                $this->validateBotRentalPeriod(
                    $validator,
                    $item['starts_on'] ?? null,
                    $item['ends_on'] ?? null,
                    "items.{$index}.starts_on",
                    "items.{$index}.ends_on",
                );
                $this->validateRentalTimeRange(
                    $validator,
                    $item['starts_on'] ?? null,
                    $item['ends_on'] ?? null,
                    $item['pickup_time'] ?? null,
                    $item['return_time'] ?? null,
                    "items.{$index}.return_time",
                );
            }
        }];
    }
}
