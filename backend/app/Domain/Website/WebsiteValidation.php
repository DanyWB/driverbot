<?php

namespace App\Domain\Website;

class WebsiteValidation
{
    /** @return array<string, string> */
    public static function messages(string $locale): array
    {
        $message = fn (string $key): string => __('website.validation.'.$key, [], $locale);

        return [
            'starts_on.after_or_equal' => __('website.pickup_date_unavailable', ['date' => WebsitePickupDate::earliest()->format('Y-m-d')], $locale),
            'required' => $message('required'), 'required_with' => $message('required'), 'accepted' => $message('accepted'),
            'date_format' => $message('dates'), 'after' => $message('dates'),
            'after_or_equal' => $message('dates'), 'before_or_equal' => $message('dates'),
            'email' => $message('contact'), 'regex' => $message('contact'),
            'max' => $message('limit'), 'in' => $message('invalid'), 'uuid' => $message('invalid'),
            'string' => $message('invalid'), 'integer' => $message('invalid'), 'boolean' => $message('invalid'),
            'between' => $message('invalid'), 'min' => $message('invalid'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(string $locale): array
    {
        $attributes = [];
        foreach (['name', 'contact_channel', 'contact_value', 'starts_on', 'ends_on', 'client_comment', 'brand', 'model', 'color', 'engine_cc', 'year', 'seats', 'abs', 'transmission'] as $field) {
            $attributes[$field] = __('website.'.$field, [], $locale);
        }
        $attributes['from'] = __('website.starts_on', [], $locale);
        $attributes['to'] = __('website.ends_on', [], $locale);
        $attributes['category_id'] = __('website.category', [], $locale);

        return $attributes;
    }
}
