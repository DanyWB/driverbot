<?php

namespace App\Domain\Notifications\Exceptions;

use RuntimeException;
use Throwable;

class NotificationDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function transient(string $message, ?int $retryAfterSeconds = null, ?Throwable $previous = null): self
    {
        return new self($message, true, $retryAfterSeconds, $previous);
    }

    public static function permanent(string $message, ?Throwable $previous = null): self
    {
        return new self($message, false, previous: $previous);
    }
}
