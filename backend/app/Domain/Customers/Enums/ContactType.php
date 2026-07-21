<?php

namespace App\Domain\Customers\Enums;

enum ContactType: string
{
    case Phone = 'phone';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case TelegramUsername = 'telegram_username';
}
