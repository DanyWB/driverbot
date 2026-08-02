<?php

return [
    'scheduler_heartbeat' => [
        'cache_key' => env('SCHEDULER_HEARTBEAT_CACHE_KEY', 'operations:scheduler-heartbeat'),
        'ttl_seconds' => (int) env('SCHEDULER_HEARTBEAT_TTL_SECONDS', 600),
        'max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS', 180),
    ],
];
