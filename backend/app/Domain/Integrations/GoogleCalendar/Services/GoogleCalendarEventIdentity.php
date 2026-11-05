<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Models\Booking;

/** Stable identity for one logical remote event; never include a revision or lease token. */
class GoogleCalendarEventIdentity
{
    public function for(Booking $booking, GoogleCalendarEventType $eventType): string
    {
        // Google Calendar accepts client-supplied resource IDs using lowercase base32hex.
        // A lowercase hexadecimal SHA-256 digest is within that alphabet and length range.
        return hash('sha256', sprintf('drive-phangan:gcal:event:v1:%s:%s', $booking->public_id, $eventType->value));
    }
}
