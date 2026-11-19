<?php

namespace App\Domain\Integrations\GoogleCalendar\Data;

final readonly class GoogleCalendarReconciliationClaim
{
    public function __construct(
        public int $reconciliationId,
        public int $bookingId,
        public string $eventType,
        public int $generation,
        public string $leaseToken,
    ) {}
}
