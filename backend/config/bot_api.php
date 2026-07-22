<?php

return [
    'auth_rate_limit_per_minute' => (int) env('BOT_API_AUTH_RATE_LIMIT_PER_MINUTE', 30),
    'service_rate_limit_per_minute' => (int) env(
        'BOT_API_SERVICE_RATE_LIMIT_PER_MINUTE',
        env('BOT_API_RATE_LIMIT_PER_MINUTE', 600),
    ),
    'user_rate_limit_per_minute' => (int) env('BOT_API_USER_RATE_LIMIT_PER_MINUTE', 90),
    'idempotency_ttl_hours' => (int) env('BOT_API_IDEMPOTENCY_TTL_HOURS', 72),
    'max_batch_size' => (int) env('BOT_API_MAX_BATCH_SIZE', 10),
    'max_rental_days' => (int) env('BOT_API_MAX_RENTAL_DAYS', 366),
    'token_bytes' => 32,
];
