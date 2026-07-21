<?php

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

class PricingException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
