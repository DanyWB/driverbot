<?php

$backoff = array_values(array_filter(
    array_map('intval', explode(',', (string) env('NOTIFICATION_BACKOFF_SECONDS', '60,300,900,3600,21600'))),
    fn (int $seconds): bool => $seconds > 0,
));

return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'admin_locale' => env('TELEGRAM_ADMIN_LOCALE', 'ru'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'timeout_seconds' => (int) env('TELEGRAM_HTTP_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => (int) env('TELEGRAM_CONNECT_TIMEOUT_SECONDS', 5),
    ],
    'outbox' => [
        'queue' => env('NOTIFICATION_QUEUE', 'notifications'),
        'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 8),
        'recipient_batch_size' => (int) env('NOTIFICATION_RECIPIENT_BATCH_SIZE', 50),
        'delivery_batch_size' => (int) env('NOTIFICATION_DELIVERY_BATCH_SIZE', 25),
        'processing_stale_minutes' => (int) env('NOTIFICATION_PROCESSING_STALE_MINUTES', 10),
        'monitor_overdue_minutes' => (int) env('NOTIFICATION_MONITOR_OVERDUE_MINUTES', 15),
        'backoff_seconds' => $backoff === [] ? [60, 300, 900, 3600, 21600] : $backoff,
        'sent_retention_days' => (int) env('NOTIFICATION_SENT_RETENTION_DAYS', 90),
        'discarded_retention_days' => (int) env('NOTIFICATION_DISCARDED_RETENTION_DAYS', 30),
    ],
    'reminders' => [
        'day_time' => env('BOOKING_DAY_REMINDER_TIME', '08:00'),
        'reconcile_horizon_days' => (int) env('BOOKING_REMINDER_HORIZON_DAYS', 45),
    ],
];
