<?php

namespace App\Domain\Customers\Enums;

enum IdentityProvider: string
{
    case Telegram = 'telegram';
    case Web = 'web';
}
