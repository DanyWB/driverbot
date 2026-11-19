<?php

namespace App\Domain\Integrations\GoogleCalendar\Enums;

enum GoogleCalendarOperation: string
{
    case Upsert = 'upsert';
    case Delete = 'delete';
}
