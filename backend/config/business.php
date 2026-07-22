<?php

return [
    'timezone' => env('BUSINESS_TIMEZONE', 'Asia/Bangkok'),
    'currency' => env('BUSINESS_CURRENCY', 'THB'),
    'pending_ttl_hours' => (int) env('BOOKING_PENDING_TTL_HOURS', 24),
    'manager_telegram' => env('MANAGER_TELEGRAM_USERNAME'),
    'csv_delimiter' => env('BOOKING_CSV_DELIMITER', ';'),
    'vehicle_photo_max_mb' => (int) env('VEHICLE_PHOTO_MAX_MB', 8),
    'customer_document_max_mb' => (int) env('CUSTOMER_DOCUMENT_MAX_MB', 10),
    'terms_version' => env('BOOKING_TERMS_VERSION', '2026-07-22'),
];
