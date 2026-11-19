<?php

namespace App\Domain\Integrations\GoogleCalendar\Exceptions;

class GoogleCalendarIdempotencyConflict extends GoogleCalendarOutboxException
{
    public function __construct()
    {
        parent::__construct(
            'idempotency_key_reused_with_different_parameters',
            'The Google Calendar request ID was already used with a different calendar ID.',
        );
    }
}
