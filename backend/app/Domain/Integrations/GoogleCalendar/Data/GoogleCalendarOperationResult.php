<?php

namespace App\Domain\Integrations\GoogleCalendar\Data;

final readonly class GoogleCalendarOperationResult
{
    /**
     * `remoteStateVerified` must only be true after the processor has read back and
     * checked the expected remote state. An HTTP write response alone is not verification.
     */
    public function __construct(
        public ?string $googleEventId = null,
        public bool $remoteStateVerified = false,
    ) {}
}
