<?php

return [
    'redis' => env('HEALTH_CHECK_REDIS', true),
    'scheduler' => env('HEALTH_CHECK_SCHEDULER', false),
];
