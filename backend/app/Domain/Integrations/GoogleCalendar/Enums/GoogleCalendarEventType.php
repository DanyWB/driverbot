<?php

namespace App\Domain\Integrations\GoogleCalendar\Enums;

enum GoogleCalendarEventType: string
{
    case Pickup = 'pickup';
    case ReturnEvent = 'return';
}
