<?php

return [
    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Bangkok'),
    'currency' => env('BUSINESS_CURRENCY', 'THB'),
    'pending_ttl_hours' => (int) env('BOOKING_PENDING_TTL_HOURS', 24),
    'manager_telegram' => env('MANAGER_TELEGRAM_USERNAME'),
];
