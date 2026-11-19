<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

/** Keep an unconfigured optional integration out of booking transactions. */
class GoogleCalendarConfiguration
{
    public function ready(): bool
    {
        $driver = config('google_calendar.driver');
        if (! in_array($driver, ['local', 'google'], true) || ($driver === 'local' && app()->isProduction())) {
            return false;
        }

        foreach (['pickup', 'return'] as $type) {
            $calendarId = config('google_calendar.calendars.'.$type);
            if (! is_string($calendarId) || trim($calendarId) === '') {
                return false;
            }
        }

        return true;
    }
}
