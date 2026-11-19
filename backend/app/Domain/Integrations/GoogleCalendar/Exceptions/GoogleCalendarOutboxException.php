<?php

namespace App\Domain\Integrations\GoogleCalendar\Exceptions;

use DomainException;

class GoogleCalendarOutboxException extends DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
