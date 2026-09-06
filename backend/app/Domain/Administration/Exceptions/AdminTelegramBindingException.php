<?php

namespace App\Domain\Administration\Exceptions;

use RuntimeException;

class AdminTelegramBindingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(
            'telegram_binding_code_invalid',
            'The Telegram binding code is invalid or has expired.',
            422,
        );
    }

    public static function telegramAccountAlreadyBound(): self
    {
        return new self(
            'telegram_account_already_bound',
            'This Telegram account is already connected to another administrator.',
            409,
        );
    }
}
