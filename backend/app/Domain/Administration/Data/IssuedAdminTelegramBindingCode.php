<?php

namespace App\Domain\Administration\Data;

use DateTimeInterface;

final readonly class IssuedAdminTelegramBindingCode
{
    public function __construct(
        public string $code,
        public DateTimeInterface $expiresAt,
    ) {}
}
