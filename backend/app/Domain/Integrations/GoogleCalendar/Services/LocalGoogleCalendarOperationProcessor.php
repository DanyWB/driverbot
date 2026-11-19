<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationResult;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use Illuminate\Support\Facades\Log;

/** Simulates remote success for local processing; it never contacts Google. */
class LocalGoogleCalendarOperationProcessor implements GoogleCalendarOperationProcessor
{
    public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
    {
        if (app()->isProduction()) {
            throw GoogleCalendarProcessingException::permanent('The local simulator is disabled in production.');
        }

        if (! $context->renewLease()) {
            throw GoogleCalendarProcessingException::transient('Calendar lease was lost before simulated processing.');
        }

        Log::info('google_calendar.operation_simulated', [
            'booking_public_id' => $request->bookingPublicId,
            'event_type' => $request->eventType->value,
            'operation' => $request->operation->value,
            'revision' => $request->revision,
        ]);

        return new GoogleCalendarOperationResult($request->googleEventId);
    }
}
