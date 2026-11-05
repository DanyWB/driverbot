<?php

namespace App\Domain\Integrations\GoogleCalendar\Data;

use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;

final readonly class GoogleCalendarOperationRequest
{
    /** @param array<string, mixed>|null $eventPayload */
    public function __construct(
        public string $bookingPublicId,
        public GoogleCalendarEventType $eventType,
        public GoogleCalendarOperation $operation,
        public string $calendarId,
        /** Stable Google resource ID shared by every revision/retry of this logical event. */
        public string $googleEventId,
        public int $revision,
        public ?array $eventPayload = null,
    ) {}
}
