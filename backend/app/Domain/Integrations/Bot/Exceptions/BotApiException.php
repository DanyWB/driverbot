<?php

namespace App\Domain\Integrations\Bot\Exceptions;

use RuntimeException;

class BotApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $fields = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
