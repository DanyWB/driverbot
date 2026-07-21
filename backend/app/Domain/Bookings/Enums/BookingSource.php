<?php

namespace App\Domain\Bookings\Enums;

enum BookingSource: string
{
    case Telegram = 'telegram';
    case AdminPhone = 'admin_phone';
    case AdminWhatsApp = 'admin_whatsapp';
    case AdminInstagram = 'admin_instagram';
    case AdminManual = 'admin_manual';
    case Website = 'website';
}
