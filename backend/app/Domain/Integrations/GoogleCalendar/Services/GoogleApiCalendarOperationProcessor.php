<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationResult;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real Google Calendar API v3 adapter (service-account auth).
 * Uses the stable request.googleEventId as the Google event `id`, so retries never duplicate events.
 */
class GoogleApiCalendarOperationProcessor implements GoogleCalendarOperationProcessor
{
    public function __construct(private readonly GoogleServiceAccountTokenProvider $tokens) {}

    public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
    {
        return $request->operation === GoogleCalendarOperation::Delete
            ? $this->delete($request, $context)
            : $this->upsert($request, $context);
    }

    private function upsert(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
    {
        $desired = $this->eventBody($request);

        $this->ensureLease($context);
        $existing = $this->read($request, $context);

        $this->ensureLease($context);
        if ($existing === null) {
            $response = $this->write($context, fn (PendingRequest $http) => $http->post($this->eventsUrl($request), ['id' => $request->googleEventId, ...$desired]));
            if ($response->status() === 409) {
                // The ID already exists (e.g. an earlier uncertain insert, or a cancelled event): update it in place.
                $this->ensureLease($context);
                $response = $this->write($context, fn (PendingRequest $http) => $http->put($this->eventUrl($request), $desired));
            }
        } else {
            $response = $this->write($context, fn (PendingRequest $http) => $http->put($this->eventUrl($request), $desired));
        }
        $this->assertWriteSucceeded($response);

        $this->ensureLease($context);
        $remote = $this->read($request, $context);

        return new GoogleCalendarOperationResult(
            $request->googleEventId,
            remoteStateVerified: $remote !== null && $this->matches($remote, $desired),
        );
    }

    private function delete(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
    {
        $this->ensureLease($context);
        $response = $this->write($context, fn (PendingRequest $http) => $http->delete($this->eventUrl($request)));
        // 404/410: already deleted (or never created) — the desired state already holds.
        if (! in_array($response->status(), [404, 410], true)) {
            $this->assertWriteSucceeded($response);
        }

        $this->ensureLease($context);
        $remote = $this->read($request, $context);

        return new GoogleCalendarOperationResult(
            $request->googleEventId,
            remoteStateVerified: $remote === null || ($remote['status'] ?? null) === 'cancelled',
        );
    }

    /** @return array<string, mixed>|null Null when the event does not exist. */
    private function read(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): ?array
    {
        try {
            $response = $this->http($context)->get($this->eventUrl($request));
        } catch (ConnectionException $exception) {
            throw GoogleCalendarProcessingException::transient('Google Calendar read timed out: '.$exception->getMessage(), $exception);
        }

        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }
        if (! $response->successful()) {
            throw $this->classifyFailure($response, writePhase: false);
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /** @param callable(PendingRequest): Response $send */
    private function write(GoogleCalendarOperationContext $context, callable $send): Response
    {
        try {
            return $send($this->http($context));
        } catch (ConnectionException $exception) {
            throw $context->classifyTransportTimeout($exception);
        }
    }

    private function assertWriteSucceeded(Response $response): void
    {
        if (! $response->successful()) {
            throw $this->classifyFailure($response, writePhase: true);
        }
    }

    private function classifyFailure(Response $response, bool $writePhase): GoogleCalendarProcessingException
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? '');
        $message = sprintf('Google Calendar API HTTP %d%s: %s', $status, $reason !== '' ? " ({$reason})" : '', mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 300));

        if ($status === 401) {
            $this->forgetToken();

            return GoogleCalendarProcessingException::transient($message);
        }
        if ($status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true))) {
            return GoogleCalendarProcessingException::transient($message);
        }
        if ($status >= 500) {
            // A 5xx after a write does not prove the write was not applied.
            return $writePhase
                ? GoogleCalendarProcessingException::transient($message, remoteOutcomeUnknown: true)
                : GoogleCalendarProcessingException::transient($message);
        }

        // 400 (bad payload), 403 (no access to calendar), 404 (calendar not found) — retrying will not help.
        return GoogleCalendarProcessingException::permanent($message);
    }

    private function http(GoogleCalendarOperationContext $context): PendingRequest
    {
        return $context->configureHttpRequest(
            Http::withToken($this->tokens->token($context))->acceptJson()->asJson()
        );
    }

    private function ensureLease(GoogleCalendarOperationContext $context): void
    {
        if (! $context->renewLease()) {
            throw GoogleCalendarProcessingException::transient('Calendar lease was lost before the next Google Calendar request.');
        }
    }

    /** @return array<string, mixed> */
    private function eventBody(GoogleCalendarOperationRequest $request): array
    {
        $payload = $request->eventPayload ?? [];
        if (! isset($payload['summary'], $payload['start'], $payload['end'])) {
            throw GoogleCalendarProcessingException::permanent('Google Calendar upsert payload is incomplete.');
        }

        return [
            'summary' => (string) $payload['summary'],
            'description' => (string) ($payload['description'] ?? ''),
            'start' => $payload['start'],
            'end' => $payload['end'],
            'status' => 'confirmed',
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $desired
     */
    private function matches(array $remote, array $desired): bool
    {
        return ($remote['status'] ?? 'confirmed') !== 'cancelled'
            && (string) ($remote['summary'] ?? '') === $desired['summary']
            && (string) ($remote['description'] ?? '') === $desired['description']
            && $this->sameTime($remote['start'] ?? null, $desired['start'])
            && $this->sameTime($remote['end'] ?? null, $desired['end']);
    }

    private function sameTime(mixed $remote, mixed $desired): bool
    {
        if (! is_array($remote) || ! is_array($desired)) {
            return false;
        }
        if (isset($desired['date'])) {
            return ($remote['date'] ?? null) === $desired['date'];
        }
        if (! isset($desired['dateTime'], $remote['dateTime'])) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $remote['dateTime'])->equalTo(CarbonImmutable::parse((string) $desired['dateTime']));
        } catch (Throwable) {
            return false;
        }
    }

    private function eventsUrl(GoogleCalendarOperationRequest $request): string
    {
        return rtrim((string) config('google_calendar.api_base_url'), '/').'/calendars/'.rawurlencode($request->calendarId).'/events';
    }

    private function eventUrl(GoogleCalendarOperationRequest $request): string
    {
        return $this->eventsUrl($request).'/'.rawurlencode($request->googleEventId);
    }

    private function forgetToken(): void
    {
        try {
            Cache::forget('google_calendar:access_token:'.hash('sha256', $this->tokens->credentials()['client_email']));
        } catch (Throwable) {
            // Credentials errors surface on the next attempt.
        }
    }
}
