<?php

namespace App\Domain\Integrations\GoogleCalendar\Contracts;

use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationResult;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;

/**
 * Remote adapters must use request.googleEventId as the Google Event resource `id` on insert,
 * and address that same ID for get/update/delete. Google documents client supplied event IDs
 * and notes that they prevent duplicate event creation after an uncertain insert response.
 * On an insert conflict/uncertain timeout, get the event by this ID and reconcile its payload;
 * treat delete-not-found as an already-applied delete. The processor must honor context timeout
 * and call renewLease before starting another remote phase; false means it is no longer owner.
 * For HTTP, call context.configureHttpRequest() on every PendingRequest and classify
 * ConnectionException through context.classifyTransportTimeout(), and other ambiguous HTTP
 * responses through context.classifyUncertainRemoteOutcome(); never interpret these as proof
 * that the remote side did not apply the operation. The worker's lease outlives that bounded
 * transport deadline by at least ten seconds; renewal cannot run while a synchronous send blocks.
 */
interface GoogleCalendarOperationProcessor
{
    public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult;
}
