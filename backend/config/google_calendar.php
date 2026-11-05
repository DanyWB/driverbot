<?php

return [
    'calendars' => [
        'pickup' => env('GOOGLE_CALENDAR_PICKUP_ID'),
        'return' => env('GOOGLE_CALENDAR_RETURN_ID'),
    ],
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
