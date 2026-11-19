<?php

namespace App\Domain\Integrations\GoogleCalendar\Data;

final readonly class GoogleCalendarOutboxClaim
{
    public function __construct(public int $outboxId, public string $leaseToken) {}
}
