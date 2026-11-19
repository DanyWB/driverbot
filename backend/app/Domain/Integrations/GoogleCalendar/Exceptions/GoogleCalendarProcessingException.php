<?php

namespace App\Domain\Integrations\GoogleCalendar\Exceptions;

use RuntimeException;
use Throwable;

class GoogleCalendarProcessingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, ?Throwable $previous = null, public readonly bool $remoteOutcomeUnknown = false)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null, bool $remoteOutcomeUnknown = false): self
    {
        return new self($message, true, $previous, $remoteOutcomeUnknown);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, false, $previous);
    }
}
