<?php

namespace App\Domain\Integrations\GoogleCalendar\Data;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;

final readonly class GoogleCalendarOperationContext
{
    /** @param Closure(): bool $renewLease */
    public function __construct(
        public int $timeoutSeconds,
        private Closure $renewLease,
    ) {}

    /** Must be checked before starting another remote phase and after waits. */
    public function renewLease(): bool
    {
        return ($this->renewLease)();
    }

    /** Apply real connect and total request deadlines; a future HTTP adapter must use this on every request. */
    public function configureHttpRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->connectTimeout(min(10, $this->timeoutSeconds))
            ->timeout($this->timeoutSeconds);
    }

    /** A transport timeout does not prove the remote service rejected the operation. */
    public function classifyTransportTimeout(ConnectionException $exception): GoogleCalendarProcessingException
    {
        return $this->classifyUncertainRemoteOutcome('Google Calendar transport timed out; remote outcome is unknown.', $exception);
    }

    public function classifyUncertainRemoteOutcome(string $message, ?\Throwable $previous = null): GoogleCalendarProcessingException
    {
        return GoogleCalendarProcessingException::transient($message, $previous, remoteOutcomeUnknown: true);
    }
}
