<?php

return [
    // "local" simulates success without contacting Google; "google" calls the real Calendar API.
    'driver' => env('GOOGLE_CALENDAR_DRIVER', 'local'),
    'calendars' => [
        'pickup' => env('GOOGLE_CALENDAR_PICKUP_ID'),
        'return' => env('GOOGLE_CALENDAR_RETURN_ID'),
    ],
    'service_account' => [
        // Path to the service-account JSON key (absolute, or relative to the backend directory).
        'credentials_path' => env('GOOGLE_CALENDAR_CREDENTIALS_PATH'),
        // Alternative to the path: the JSON key itself (raw or base64-encoded).
        'credentials_json' => env('GOOGLE_CALENDAR_CREDENTIALS_JSON'),
    ],
    'api_base_url' => env('GOOGLE_CALENDAR_API_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
    'token_url' => env('GOOGLE_CALENDAR_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
    'event_duration_minutes' => 60,
    'outbox' => [
        'batch_size' => 50,
        'lease_seconds' => 120,
        // Processor adapters must enforce this timeout; leave room for renewal and acknowledgement.
        'processor_timeout_seconds' => 45,
        'max_attempts' => 10,
        'retry_base_seconds' => 30,
        'retry_max_seconds' => 3600,
    ],
    'convergence' => [
        // Uncertain remote work keeps recurring at a capped interval until explicitly reviewed.
        'delay_seconds' => 300,
        'max_delay_seconds' => 86400,
        // Escalation does not stop automatic convergence attempts.
        'manual_review_after_failures' => 10,
        'warning_after_checks' => 100,
        'warning_after_days' => 7,
    ],
];
