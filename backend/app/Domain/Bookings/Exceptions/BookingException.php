<?php

namespace App\Domain\Bookings\Exceptions;

use RuntimeException;
use Throwable;

class BookingException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
