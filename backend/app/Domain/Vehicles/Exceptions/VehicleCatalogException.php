<?php

namespace App\Domain\Vehicles\Exceptions;

use RuntimeException;

class VehicleCatalogException extends RuntimeException
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
