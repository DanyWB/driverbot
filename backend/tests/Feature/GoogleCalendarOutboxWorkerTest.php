<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Integrations\GoogleCalendar\Contracts\GoogleCalendarOperationProcessor;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationRequest;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationResult;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarSyncStatus;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarEventPayloadBuilder;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarEventIdentity;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxService;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxWorker;
use App\Domain\Notifications\Services\TelegramNotificationRenderer;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use App\Models\NotificationOutbox;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarOutboxWorkerTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookings;

    private Customer $customer;

    private Vehicle $vehicle;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_calendar.calendars.pickup', 'test-pickup-calendar');
        config()->set('google_calendar.calendars.return', 'test-return-calendar');
        config()->set('notifications.telegram.admin_chat_id', '123456789');
        config()->set('notifications.telegram.admin_locale', 'en');
        config()->set('google_calendar.outbox.retry_base_seconds', 1);
        config()->set('google_calendar.outbox.retry_max_seconds', 2);
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00', 'Asia/Bangkok'));
        $this->seed();
        $this->bookings = app(BookingService::class);
        $this->customer = Customer::factory()->create(['name' => 'Customer Name']);
        $this->vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => false]);
        $this->admin = User::factory()->create();
        $season = PricingSeason::query()->where('key', PricingSeasonKey::Low->value)->firstOrFail();
        VehiclePriceTier::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'pricing_season_id' => $season->id,
            'tier_key' => PricingTier::OneDay,
            'min_days' => 1,
            'max_days' => 6,
            'anchor_days' => 1,
            'package_total' => 300,
            'daily_rate' => '300.000000',
            'currency' => 'THB',
            'is_active' => true,
        ]);
    }

    public function test_processes_normal_upserts_and_deletes(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor();
        $worker = app(GoogleCalendarOutboxWorker::class);

        $result = $worker->processBatch();
        $this->assertSame(2, $result['processed']);
        $this->assertCount(2, $processor->requests);
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('sync_status', GoogleCalendarSyncStatus::Synced->value)->count());

        $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Test cancellation');
        $worker->processBatch();
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('sync_status', GoogleCalendarSyncStatus::Deleted->value)->count());
        $this->assertSame(2, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)
            ->where('status', GoogleCalendarOutbox::STATUS_PROCESSED)->where('operation', 'delete')->count());
    }

    public function test_stale_upsert_is_skipped_after_cancellation(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor();
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);

        $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Test cancellation');
        $this->assertTrue($worker->processClaim($claim));

        $this->assertCount(0, $processor->requests);
        $pickup = GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('event_type', GoogleCalendarEventType::Pickup->value)->firstOrFail();
        $this->assertSame(GoogleCalendarSyncStatus::DeletePending->value, $pickup->sync_status);
        $this->assertSame(2, (int) $pickup->last_requested_revision);
    }

    public function test_pickup_and_return_can_be_claimed_independently(): void
    {
        $booking = $this->createBooking();
        $worker = app(GoogleCalendarOutboxWorker::class);
        $first = $worker->claimNext();
        $second = $worker->claimNext();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $firstType = GoogleCalendarOutbox::query()->findOrFail($first->outboxId)->event_type;
        $secondType = GoogleCalendarOutbox::query()->findOrFail($second->outboxId)->event_type;
        $this->assertNotSame($firstType, $secondType);
        $this->assertContains($firstType, ['pickup', 'return']);
        $this->assertContains($secondType, ['pickup', 'return']);
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->whereNotNull('sync_lease_token')->count());
    }

    public function test_second_worker_cannot_claim_same_event_and_expired_lease_is_recoverable(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $worker = app(GoogleCalendarOutboxWorker::class);
        $competingWorker = app(GoogleCalendarOutboxWorker::class);
        $first = $worker->claimNext();
        $this->assertNotNull($first);
        $this->assertNull($competingWorker->claimNext());

        GoogleCalendarOutbox::query()->whereKey($first->outboxId)->update(['locked_until' => now()->subSecond()]);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
            ->update(['sync_locked_until' => now()->subSecond()]);
        $processor = $this->bindProcessor();
        $worker->processBatch(1);
        $this->assertFalse($worker->processClaim($first));
        $this->assertCount(1, $processor->requests);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSED,
            GoogleCalendarOutbox::query()->findOrFail($first->outboxId)->status);
        $this->assertSame(1, (int) \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->value('completed_generation'));
    }

    public function test_expired_upsert_is_reconciled_from_cancelled_state_without_original_worker_returning(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $worker = app(GoogleCalendarOutboxWorker::class);
        $abandoned = $worker->claimNext();
        $this->assertNotNull($abandoned);
        $outbox = GoogleCalendarOutbox::query()->findOrFail($abandoned->outboxId);
        $this->assertSame('pickup', $outbox->event_type);

        $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Crash recovery cancellation');
        GoogleCalendarOutbox::query()->whereKey($abandoned->outboxId)->update(['locked_until' => now()->subSecond()]);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
            ->update(['sync_locked_until' => now()->subSecond()]);
        $processor = $this->bindProcessor();

        $worker->processBatch(1); // Simulates a dead original process: only durable recovery runs.
        $worker->processBatch(1); // Repeated recovery remains coalesced to the same mapping row.

        $this->assertSame(GoogleCalendarOperation::Delete, $processor->requests[0]->operation);
        $this->assertSame(GoogleCalendarEventType::Pickup, $processor->requests[0]->eventType);
        $this->assertSame(GoogleCalendarSyncStatus::Deleted->value, GoogleCalendarEvent::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->value('sync_status'));
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSED, $outbox->fresh()->status);
        $this->assertSame(1, \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());
        $this->assertSame(1, count($processor->requests));
    }

    public function test_delete_completed_before_late_upsert_return_triggers_another_durable_reconciliation(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);
        $remoteExists = false;
        $started = false;
        $this->bindProcessor(function (GoogleCalendarOperationRequest $request) use (&$remoteExists, &$started, $worker, $booking): GoogleCalendarOperationResult {
            if ($request->operation === GoogleCalendarOperation::Upsert && ! $started) {
                $started = true;
                $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Late upsert race');
                GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
                    ->where('status', GoogleCalendarOutbox::STATUS_PROCESSING)->update(['locked_until' => now()->subSecond()]);
                GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
                    ->update(['sync_locked_until' => now()->subSecond()]);
                $worker->processBatch(1); // Durable recovery processes the cancellation delete first.
                $this->assertFalse($remoteExists);
                $remoteExists = true; // The original HTTP request is accepted after that delete.
            } elseif ($request->operation === GoogleCalendarOperation::Delete) {
                $remoteExists = false;
            }
            return new GoogleCalendarOperationResult($request->googleEventId);
        });

        $this->assertFalse($worker->processClaim($claim));
        $reconciliation = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertGreaterThan($reconciliation->completed_generation, $reconciliation->requested_generation);

        $worker->processBatch(1);
        $this->assertFalse($remoteExists);
    }

    public function test_reconciliation_triggers_are_coalesced_per_event_mapping(): void
    {
        $booking = $this->createBooking();
        $service = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class);
        $service->request($booking, GoogleCalendarEventType::Pickup);
        $service->request($booking, GoogleCalendarEventType::Pickup);
        $service->request($booking, GoogleCalendarEventType::Pickup);

        $this->assertSame(1, \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());
        $this->assertSame(1, (int) \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->value('requested_generation'));
    }

    public function test_expired_reconciliation_itself_is_reclaimed_and_processed(): void
    {
        $booking = $this->createBooking();
        app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class)
            ->request($booking, GoogleCalendarEventType::Pickup);
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationWorker::class)->claimNext();
        $this->assertNotNull($claim);
        \App\Models\GoogleCalendarReconciliation::query()->whereKey($claim->reconciliationId)->update(['locked_until' => now()->subSecond()]);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
            ->update(['sync_locked_until' => now()->subSecond()]);
        $processor = $this->bindProcessor();

        $worker->processBatch(1);

        $row = \App\Models\GoogleCalendarReconciliation::query()->findOrFail($claim->reconciliationId);
        $this->assertSame(1, (int) $row->completed_generation);
        $this->assertSame(1, count($processor->requests));
    }

    public function test_uncertain_cancelled_mapping_converges_again_after_a_late_upsert_and_worker_restart(): void
    {
        config()->set('google_calendar.convergence.delay_seconds', 5);
        config()->set('google_calendar.convergence.max_delay_seconds', 10);
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $workerA = app(GoogleCalendarOutboxWorker::class);
        $abandoned = $workerA->claimNext();
        $this->assertNotNull($abandoned);

        $remote = [];
        $processor = $this->bindProcessor(function (GoogleCalendarOperationRequest $request) use (&$remote): GoogleCalendarOperationResult {
            if ($request->operation === \App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation::Delete) {
                unset($remote[$request->googleEventId]);
            } else {
                $remote[$request->googleEventId] = $request->eventPayload;
            }
            return new GoogleCalendarOperationResult($request->googleEventId);
        });

        $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Remote convergence test');
        GoogleCalendarOutbox::query()->whereKey($abandoned->outboxId)->update(['locked_until' => now()->subSecond()]);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
            ->update(['sync_locked_until' => now()->subSecond()]);

        // Recovery acts without Worker A returning and deletes the current desired event.
        $workerA->processBatch(1);
        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertArrayNotHasKey($mapping->google_event_id, $remote);
        $reconciliation = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertNotNull($reconciliation->uncertain_since);
        $this->assertGreaterThan($reconciliation->completed_generation, $reconciliation->requested_generation);

        // The abandoned request is accepted after the first repair completed.
        $remote[$mapping->google_event_id] = ['summary' => 'late old upsert'];
        $this->travel(6)->seconds();

        // A fresh worker instance represents a process restart between durable checks.
        $workerB = app(GoogleCalendarOutboxWorker::class);
        $workerB->processBatch(1);
        $this->assertArrayNotHasKey($mapping->google_event_id, $remote);
        $this->assertSame(1, \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());

        // Even a later stale side effect remains repairable by another delayed check.
        $remote[$mapping->google_event_id] = ['summary' => 'another delayed old update'];
        $this->travel(11)->seconds();
        app(GoogleCalendarOutboxWorker::class)->processBatch(1);

        $this->assertArrayNotHasKey($mapping->google_event_id, $remote);
        $this->assertNotNull($reconciliation->fresh()->uncertain_since);
        $this->assertSame(3, (int) $reconciliation->fresh()->convergence_checks);
        $this->assertSame(GoogleCalendarSyncStatus::Deleted->value, $mapping->fresh()->sync_status);
        $this->assertCount(3, $processor->requests);
    }

    public function test_repeated_triggers_coalesce_while_delayed_convergence_is_pending(): void
    {
        $booking = $this->createBooking();
        $service = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class);
        $row = $service->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);
        $this->assertNotNull($row);
        $requested = (int) $row->requested_generation;

        $service->request($booking, GoogleCalendarEventType::Pickup);
        $service->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);

        $this->assertSame(1, \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());
        $this->assertSame($requested, (int) $row->fresh()->requested_generation);
        $this->assertNotNull($row->fresh()->uncertain_since);
    }

    public function test_convergence_uses_latest_revision_and_retains_completed_and_no_show_events(): void
    {
        $completed = $this->createBooking(BookingStatus::Approved);
        $completed = $this->bookings->activate($completed, BookingActor::admin((int) $this->admin->id));
        $completed = $this->bookings->complete($completed, BookingActor::admin((int) $this->admin->id));
        $noShow = $this->createBooking(BookingStatus::Approved);
        $noShow = $this->bookings->markNoShow(
            $noShow,
            BookingActor::admin((int) $this->admin->id),
            at: CarbonImmutable::parse('2026-08-10 10:00:00', 'Asia/Bangkok'),
        );
        $service = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class);
        foreach ([$completed, $noShow] as $booking) {
            $service->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);
        }

        // A newer booking revision arrives before the check; reconciliation must render that state.
        Booking::query()->whereKey($completed->id)->update(['starts_on' => '2026-08-11']);
        app(GoogleCalendarOutboxService::class)->upsert(
            $completed->fresh(),
            GoogleCalendarEventType::Pickup,
            'test-pickup-calendar',
            'convergence-latest-revision',
        );

        $processor = $this->bindProcessor();
        app(GoogleCalendarOutboxWorker::class)->processBatch(2);

        $completedRequest = collect($processor->requests)->first(fn (GoogleCalendarOperationRequest $request): bool => $request->bookingPublicId === $completed->public_id);
        $noShowRequest = collect($processor->requests)->first(fn (GoogleCalendarOperationRequest $request): bool => $request->bookingPublicId === $noShow->public_id);
        $this->assertNotNull($completedRequest);
        $this->assertSame(2, $completedRequest->revision);
        $this->assertSame('2026-08-11T10:00:00+07:00', $completedRequest->eventPayload['start']['dateTime']);
        $this->assertSame(BookingStatus::Completed, $completed->fresh()->bookingStatus());
        $this->assertNotNull($noShowRequest);
        $this->assertSame('Status: NO SHOW', $noShowRequest->eventPayload['description']);
        $this->assertSame(BookingStatus::NoShow, $noShow->fresh()->bookingStatus());
        $this->assertNotContains(\App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation::Delete,
            collect($processor->requests)->pluck('operation')->all());
        foreach ([$completed, $noShow] as $booking) {
            $this->assertNotNull(\App\Models\GoogleCalendarReconciliation::query()
                ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail()->uncertain_since);
        }
    }

    public function test_repeated_uncertain_failures_escalate_to_manual_review_without_clearing_convergence(): void
    {
        config()->set('google_calendar.convergence.manual_review_after_failures', 2);
        config()->set('google_calendar.outbox.retry_base_seconds', 1);
        config()->set('google_calendar.outbox.retry_max_seconds', 1);
        $booking = $this->createBooking();
        app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class)
            ->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);
        $this->bindProcessor(fn () => throw GoogleCalendarProcessingException::transient(
            'Remote result remains unknown.', remoteOutcomeUnknown: true,
        ));
        $worker = app(GoogleCalendarOutboxWorker::class);

        $worker->processBatch(1);
        $this->travel(3)->seconds();
        $worker->processBatch(1);

        $row = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertTrue($row->manual_review_required);
        $this->assertGreaterThanOrEqual(2, (int) $row->convergence_failures);
        $this->assertNotNull($row->uncertain_since);
        $this->assertGreaterThan($row->completed_generation, $row->requested_generation);
        $this->assertSame(1, \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->count());
    }

    public function test_notification_failure_does_not_rollback_uncertain_remote_tracking(): void
    {
        $booking = $this->createBooking();
        $notifications = \Mockery::mock(\App\Domain\Notifications\Services\NotificationOutboxService::class);
        $notifications->shouldReceive('enqueueAdmins')->once()->andThrow(new \RuntimeException('Notification store unavailable.'));
        $this->app->instance(\App\Domain\Notifications\Services\NotificationOutboxService::class, $notifications);
        $this->bindProcessor(fn () => new GoogleCalendarOperationResult('unexpected-event-id'));

        app(GoogleCalendarOutboxWorker::class)->processBatch(1);

        $row = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertNotNull($row->uncertain_since);
        $this->assertSame('unexpected-event-id', $row->unexpected_event_id);
        $this->assertTrue($row->manual_review_required);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSED, GoogleCalendarOutbox::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail()->status);
    }

    public function test_last_remote_verification_is_recorded_only_when_processor_confirms_readback(): void
    {
        $booking = $this->createBooking();
        app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class)
            ->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);
        $this->bindProcessor(fn (GoogleCalendarOperationRequest $request): GoogleCalendarOperationResult =>
            new GoogleCalendarOperationResult($request->googleEventId, remoteStateVerified: true));

        app(GoogleCalendarOutboxWorker::class)->processBatch(1);

        $row = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertNotNull(GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->value('last_remote_verified_at'));
        $this->assertNotNull($row->uncertain_since, 'Point-in-time readback must not clear unresolved in-flight uncertainty.');
    }

    public function test_convergence_diagnostics_show_state_and_never_render_contact_identifiers(): void
    {
        $booking = $this->createBooking();
        $privatePhone = '+66 81 234 5678';
        $privateTelegramId = '987654321012';
        $privatePassport = 'AB1234567';
        $booking->customer->replacePrivateData([
            'phone' => $privatePhone,
            'telegram_id' => $privateTelegramId,
            'passport_number' => $privatePassport,
        ]);
        $booking->customer->save();
        app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class)
            ->request($booking, GoogleCalendarEventType::Pickup, remoteOutcomeUnknown: true);
        \App\Models\GoogleCalendarReconciliation::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')
            ->update([
                'last_error' => "Remote response leaked {$privatePhone}, {$privatePassport}, Telegram {$privateTelegramId}",
                'unexpected_event_id' => 'unexpected-synthetic-id',
                'manual_review_required' => true,
            ]);

        Artisan::call('google-calendar:convergence-status');
        $output = Artisan::output();

        $this->assertStringContainsString((string) $booking->public_id, $output);
        $this->assertStringContainsString('overdue', strtolower($output));
        $this->assertStringContainsString('Remote operation failed', $output);
        $this->assertStringContainsString('unexpected-synthetic-id', $output);
        $this->assertStringContainsString('manual review', strtolower($output));
        $this->assertStringNotContainsString($privatePhone, $output);
        $this->assertStringNotContainsString($privateTelegramId, $output);
        $this->assertStringNotContainsString($privatePassport, $output);
    }

    public function test_lease_token_mismatch_cannot_process_or_acknowledge(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor();
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $outbox = GoogleCalendarOutbox::query()->findOrFail($claim->outboxId);
        $outbox->forceFill(['lease_token' => (string) \Illuminate\Support\Str::uuid()])->save();

        $this->assertFalse($worker->processClaim($claim));
        $this->assertCount(0, $processor->requests);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSING, $outbox->fresh()->status);
        $this->assertSame(1, (int) GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('event_type', $outbox->event_type)->value('last_applied_revision'));
    }

    public function test_mapping_lease_token_mismatch_cannot_process_or_acknowledge(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor();
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $outbox = GoogleCalendarOutbox::query()->findOrFail($claim->outboxId);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', $outbox->event_type)
            ->update(['sync_lease_token' => (string) \Illuminate\Support\Str::uuid()]);

        $this->assertFalse($worker->processClaim($claim));
        $this->assertCount(0, $processor->requests);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSING, $outbox->fresh()->status);
    }

    public function test_revision_mismatch_skips_superseded_operation(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor();
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $row = GoogleCalendarOutbox::query()->findOrFail($claim->outboxId);
        app(GoogleCalendarOutboxService::class)->upsert(
            $booking,
            GoogleCalendarEventType::from($row->event_type),
            (string) GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', $row->event_type)->value('calendar_id'),
            'newer-logical-operation',
        );

        $this->assertTrue($worker->processClaim($claim));
        $this->assertCount(0, $processor->requests);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSED, $row->fresh()->status);
        $this->assertSame(2, (int) GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('event_type', $row->event_type)->value('last_requested_revision'));
    }

    public function test_transient_failure_retries_and_permanent_failure_stops(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $processor = $this->bindProcessor(fn () => throw GoogleCalendarProcessingException::transient('Temporary failure.'));
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertFalse($worker->processClaim($claim));
        $outbox = GoogleCalendarOutbox::query()->findOrFail($claim->outboxId);
        $this->assertSame(GoogleCalendarOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, (int) $outbox->retry_count);
        $this->assertGreaterThan(now(), $outbox->available_at);

        $processor->handler = fn () => throw GoogleCalendarProcessingException::permanent('Invalid request.');
        $outbox->forceFill(['available_at' => now()->subSecond()])->save();
        $claim = $worker->claimNext();
        $this->assertFalse($worker->processClaim($claim));
        $this->assertSame(GoogleCalendarOutbox::STATUS_FAILED, $outbox->fresh()->status);
        $this->assertDatabaseHas('notification_outbox', [
            'event_type' => 'google_calendar.sync_failed',
            'channel' => 'internal',
        ]);
    }

    public function test_tenth_attempt_is_terminal_and_admin_notification_has_no_customer_contacts(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $processor = $this->bindProcessor(fn () => throw GoogleCalendarProcessingException::transient('Transient.'));
        $worker = app(GoogleCalendarOutboxWorker::class);
        $outbox = GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $outbox->forceFill(['available_at' => now()->subSecond()])->save();
            $claim = $worker->claimNext();
            $this->assertNotNull($claim);
            $worker->processClaim($claim);
            $outbox->refresh();
        }

        $this->assertSame(GoogleCalendarOutbox::STATUS_FAILED, $outbox->status);
        $this->assertSame(10, (int) $outbox->retry_count);
        $notice = NotificationOutbox::query()->where('event_type', 'google_calendar.sync_failed')->firstOrFail();
        $message = app(TelegramNotificationRenderer::class)->render($notice);
        $this->assertStringContainsString((string) $booking->public_id, $message->text);
        $this->assertStringNotContainsString('Customer Name', $message->text);
        $this->assertStringNotContainsString('passport', strtolower($message->text));
        $this->assertStringNotContainsString('phone', strtolower($message->text));
    }

    public function test_completed_and_no_show_cannot_be_deleted(): void
    {
        $completed = $this->createBooking(BookingStatus::Approved);
        $active = $this->bookings->activate($completed, BookingActor::admin((int) $this->admin->id));
        $this->bookings->complete($active, BookingActor::admin((int) $this->admin->id));
        $noShow = $this->createBooking(BookingStatus::Approved);
        $this->bookings->markNoShow(
            $noShow,
            BookingActor::admin((int) $this->admin->id),
            at: CarbonImmutable::parse('2026-08-10 10:00:00', 'Asia/Bangkok'),
        );

        foreach ([$completed, $noShow] as $booking) {
            try {
                app(GoogleCalendarOutboxService::class)->delete($booking, GoogleCalendarEventType::Pickup, 'test-pickup-calendar');
                $this->fail('History must not be deleted.');
            } catch (\App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarOutboxException $exception) {
                $this->assertSame('booking_history_must_be_retained', $exception->reason);
            }
        }
        $this->assertDatabaseMissing('google_calendar_outbox', ['operation' => 'delete']);
    }

    public function test_historical_completed_and_no_show_events_can_be_updated(): void
    {
        $completed = $this->createBooking(BookingStatus::Approved);
        $active = $this->bookings->activate($completed, BookingActor::admin((int) $this->admin->id));
        $this->bookings->complete($active, BookingActor::admin((int) $this->admin->id));
        $noShow = $this->createBooking(BookingStatus::Approved);
        $this->bookings->markNoShow(
            $noShow,
            BookingActor::admin((int) $this->admin->id),
            at: CarbonImmutable::parse('2026-08-10 10:00:00', 'Asia/Bangkok'),
        );
        $processor = $this->bindProcessor();
        app(GoogleCalendarOutboxWorker::class)->processBatch();

        $completedRequests = array_values(array_filter($processor->requests, fn (GoogleCalendarOperationRequest $request): bool => $request->bookingPublicId === $completed->public_id));
        $noShowRequests = array_values(array_filter($processor->requests, fn (GoogleCalendarOperationRequest $request): bool => $request->bookingPublicId === $noShow->public_id));
        $this->assertCount(2, $completedRequests);
        $this->assertCount(2, $noShowRequests);
        $this->assertSame('Status: APPROVED', $completedRequests[0]->eventPayload['description']);
        $this->assertSame('Status: NO SHOW', $noShowRequests[0]->eventPayload['description']);
    }

    #[DataProvider('payloadCases')]
    public function test_event_payload_formatting(BookingStatus $status, ?string $time, string $expectedDescription, bool $allDay): void
    {
        $booking = $this->createBooking($status === BookingStatus::NoShow ? BookingStatus::Approved : $status, $time, $time);
        if ($status === BookingStatus::NoShow) {
            $booking = $this->bookings->markNoShow(
                $booking,
                BookingActor::admin((int) $this->admin->id),
                at: CarbonImmutable::parse('2026-08-10 10:00:00', 'Asia/Bangkok'),
            );
        }
        $booking->forceFill(['contact_name' => '   ', 'contact_phone' => '+66000000000', 'contact_telegram' => 'private_handle'])->save();
        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($booking->fresh(), GoogleCalendarEventType::Pickup);
        $returnPayload = app(GoogleCalendarEventPayloadBuilder::class)->build($booking->fresh(), GoogleCalendarEventType::ReturnEvent);

        $this->assertSame("🏍 {$this->vehicle->name} | #{$booking->public_id} | Customer Name", $payload['summary']);
        $this->assertSame('Status: '.$expectedDescription, $payload['description']);
        $this->assertSame($allDay, array_key_exists('date', $payload['start']));
        if ($allDay) {
            $this->assertSame('2026-08-10', $payload['start']['date']);
            $this->assertSame('2026-08-11', $payload['end']['date']);
        } else {
            $this->assertSame('Asia/Bangkok', $payload['start']['timeZone']);
            $this->assertStringContainsString('+07:00', $payload['start']['dateTime']);
        }
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('+66000000000', $serialized);
        $this->assertStringNotContainsString('private_handle', $serialized);
        $this->assertStringNotContainsString('passport', strtolower($serialized));
        $this->assertSame("↩️ {$this->vehicle->name} | #{$booking->public_id} | Customer Name", $returnPayload['summary']);
    }

    public function test_nonblank_booking_contact_name_takes_precedence_and_is_trimmed(): void
    {
        $booking = $this->createBooking();
        $booking->forceFill(['contact_name' => '  Booking Contact  '])->save();
        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($booking->fresh(), GoogleCalendarEventType::Pickup);

        $this->assertSame("🏍 {$this->vehicle->name} | #{$booking->public_id} | Booking Contact", $payload['summary']);
    }

    public function test_remote_event_identity_is_stable_per_booking_and_event_type(): void
    {
        $booking = $this->createBooking();
        $identity = app(GoogleCalendarEventIdentity::class);
        $pickupId = $identity->for($booking, GoogleCalendarEventType::Pickup);
        $returnId = $identity->for($booking, GoogleCalendarEventType::ReturnEvent);

        app(GoogleCalendarOutboxService::class)->upsert($booking, GoogleCalendarEventType::Pickup, 'test-pickup-calendar', 'second-revision');
        $this->assertSame($pickupId, $identity->for($booking->fresh(), GoogleCalendarEventType::Pickup));
        $this->assertNotSame($pickupId, $returnId);

        $processor = $this->bindProcessor();
        app(GoogleCalendarOutboxWorker::class)->processBatch();
        $this->assertSame([$pickupId, $returnId], array_values(array_unique(array_map(
            fn (GoogleCalendarOperationRequest $request): string => $request->googleEventId,
            $processor->requests,
        ))));
    }

    public function test_timeout_after_remote_acceptance_reconciles_with_same_identity(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $accepted = [];
        $attempts = 0;
        $processor = $this->bindProcessor(function (GoogleCalendarOperationRequest $request) use (&$accepted, &$attempts): GoogleCalendarOperationResult {
            $accepted[$request->googleEventId] = true;
            $attempts++;
            if ($attempts === 1) {
                throw GoogleCalendarProcessingException::transient('Response timed out after acceptance.', remoteOutcomeUnknown: true);
            }

            return new GoogleCalendarOperationResult($request->googleEventId);
        });
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);
        $this->assertFalse($worker->processClaim($claim));

        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(1, (int) $mapping->last_requested_revision);
        $worker->processBatch(1);

        $pickupRequests = array_values(array_filter($processor->requests, fn (GoogleCalendarOperationRequest $request): bool => $request->eventType === GoogleCalendarEventType::Pickup));
        $this->assertCount(2, $pickupRequests);
        $this->assertSame($pickupRequests[0]->googleEventId, $pickupRequests[1]->googleEventId);
        $this->assertCount(1, array_keys($accepted));
    }

    public function test_retried_delete_targets_the_same_remote_identity(): void
    {
        $booking = $this->createBooking();
        $deleteAttempts = [];
        $processor = $this->bindProcessor(function (GoogleCalendarOperationRequest $request) use (&$deleteAttempts): GoogleCalendarOperationResult {
            if ($request->operation === \App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation::Delete) {
                $deleteAttempts[] = $request->googleEventId;
                if (count($deleteAttempts) === 1) {
                    throw GoogleCalendarProcessingException::transient('Delete response was lost.', remoteOutcomeUnknown: true);
                }
            }

            return new GoogleCalendarOperationResult($request->googleEventId);
        });
        $worker = app(GoogleCalendarOutboxWorker::class);
        $worker->processBatch();
        $this->bookings->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Idempotent delete');
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->where('operation', 'delete')->update(['available_at' => now()->addHour()]);
        $pickupId = app(GoogleCalendarEventIdentity::class)->for($booking, GoogleCalendarEventType::Pickup);
        $worker->processBatch(1);
        $worker->processBatch(1);

        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(GoogleCalendarSyncStatus::Deleted->value, $mapping->sync_status);
        $this->assertSame([$pickupId, $pickupId], $deleteAttempts);
        $this->assertSame($pickupId, $mapping->google_event_id);
    }

    public function test_cancellation_during_inflight_upsert_queues_authoritative_delete_reconciliation(): void
    {
        $booking = $this->createBooking();
        $called = false;
        $this->bindProcessor(function (GoogleCalendarOperationRequest $request) use (&$called, $booking): GoogleCalendarOperationResult {
            if (! $called) {
                $called = true;
                app(BookingService::class)->cancelByAdmin($booking, BookingActor::admin((int) $this->admin->id), 'Concurrent cancellation');
            }

            return new GoogleCalendarOperationResult($request->googleEventId);
        });
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);
        $this->assertFalse($worker->processClaim($claim));

        $pickup = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(GoogleCalendarSyncStatus::DeletePending->value, $pickup->sync_status);
        $this->assertSame('delete', GoogleCalendarOutbox::query()->where('booking_id', $booking->id)
            ->where('event_type', 'pickup')->where('event_revision', $pickup->last_requested_revision)->value('operation'));
        $this->assertSame(app(GoogleCalendarEventIdentity::class)->for($booking, GoogleCalendarEventType::Pickup), $pickup->google_event_id);
    }

    public function test_processor_context_renews_a_current_lease(): void
    {
        $booking = $this->createBooking();
        $contextSeen = null;
        $this->bindProcessor(function (GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context) use (&$contextSeen): GoogleCalendarOperationResult {
            $contextSeen = $context;
            $this->assertTrue($context->renewLease());
            return new GoogleCalendarOperationResult($request->googleEventId);
        });
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);
        $this->assertTrue($worker->processClaim($claim));
        $this->assertSame(45, $contextSeen->timeoutSeconds);
    }

    public function test_transport_timeout_is_classified_as_transient_with_unknown_remote_outcome(): void
    {
        $context = new GoogleCalendarOperationContext(45, fn (): bool => true);
        $failure = $context->classifyTransportTimeout(new \Illuminate\Http\Client\ConnectionException('Request timed out.'));

        $this->assertTrue($failure->retryable);
        $this->assertTrue($failure->remoteOutcomeUnknown);
        $this->assertLessThan(120, $context->timeoutSeconds);
    }

    public function test_unexpected_event_id_is_persisted_and_escalated_without_deleting_it(): void
    {
        $booking = $this->createBooking();
        $processor = $this->bindProcessor(fn () => new GoogleCalendarOperationResult('unexpected-remote-id'));
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);

        $this->assertFalse($worker->processClaim($claim));

        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $reconciliation = \App\Models\GoogleCalendarReconciliation::query()
            ->where('booking_id', $booking->id)->where('event_type', 'pickup')->firstOrFail();
        $this->assertSame(app(GoogleCalendarEventIdentity::class)->for($booking, GoogleCalendarEventType::Pickup), $mapping->google_event_id);
        $this->assertSame('unexpected-remote-id', $reconciliation->unexpected_event_id);
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'google_calendar.sync_failed']);
        $this->assertSame(0, count(array_filter($processor->requests, fn (GoogleCalendarOperationRequest $request): bool => $request->operation === GoogleCalendarOperation::Delete)));
    }

    public function test_reconciliation_triggers_during_processing_coalesce_to_one_followup_generation(): void
    {
        $booking = $this->createBooking();
        $reconciliations = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationService::class);
        $reconciliations->request($booking, GoogleCalendarEventType::Pickup);
        $worker = app(\App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarReconciliationWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);

        $reconciliations->request($booking, GoogleCalendarEventType::Pickup);
        $reconciliations->request($booking, GoogleCalendarEventType::Pickup);
        $reconciliations->request($booking, GoogleCalendarEventType::Pickup);

        $row = \App\Models\GoogleCalendarReconciliation::query()->findOrFail($claim->reconciliationId);
        $this->assertSame(2, (int) $row->requested_generation);
        $this->assertSame(1, (int) $row->claimed_generation);
    }

    public function test_processor_context_rejects_renewal_after_lease_token_reassignment(): void
    {
        $this->createBooking();
        $renewalResults = [];
        $this->bindProcessor(function (GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context) use (&$renewalResults): GoogleCalendarOperationResult {
            $renewalResults[] = $context->renewLease();
            $booking = Booking::query()->where('public_id', $request->bookingPublicId)->firstOrFail();
            GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', $request->eventType->value)
                ->where('status', GoogleCalendarOutbox::STATUS_PROCESSING)
                ->update(['lease_token' => (string) \Illuminate\Support\Str::uuid()]);
            $renewalResults[] = $context->renewLease();

            return new GoogleCalendarOperationResult($request->googleEventId);
        });
        $worker = app(GoogleCalendarOutboxWorker::class);
        $claim = $worker->claimNext();
        $this->assertNotNull($claim);
        $this->assertFalse($worker->processClaim($claim));
        $this->assertSame([true, false], $renewalResults);
    }

    public function test_expired_processing_lease_is_recovered_and_late_ack_is_rejected(): void
    {
        $booking = $this->createBooking();
        GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->where('event_type', 'return')
            ->update(['available_at' => now()->addHour()]);
        $firstWorker = app(GoogleCalendarOutboxWorker::class);
        $claim = $firstWorker->claimNext();
        $this->assertNotNull($claim);
        $outbox = GoogleCalendarOutbox::query()->findOrFail($claim->outboxId);
        GoogleCalendarOutbox::query()->whereKey($claim->outboxId)->update(['locked_until' => now()->subSecond()]);
        GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', $outbox->event_type)
            ->update(['sync_locked_until' => now()->subSecond()]);
        $processor = $this->bindProcessor();

        $firstWorker->processBatch(1);
        $this->assertFalse($firstWorker->processClaim($claim));
        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->where('event_type', $outbox->event_type)->firstOrFail();
        $this->assertSame(GoogleCalendarOutbox::STATUS_PROCESSED, $outbox->fresh()->status);
        $this->assertSame(1, (int) $mapping->last_applied_revision);
        $this->assertSame(1, count($processor->requests));
    }

    /** @return iterable<string, array{BookingStatus, string|null, string, bool}> */
    public static function payloadCases(): iterable
    {
        yield 'pending timed' => [BookingStatus::Pending, '10:00', 'PENDING APPROVAL', false];
        yield 'approved all day' => [BookingStatus::Approved, null, 'APPROVED', true];
        yield 'no show timed' => [BookingStatus::NoShow, '10:00', 'NO SHOW', false];
    }

    private function createBooking(BookingStatus $status = BookingStatus::Pending, ?string $pickupTime = '10:00', ?string $returnTime = '12:00'): Booking
    {
        return $this->bookings->create(new CreateBookingData(
            customerId: (int) $this->customer->id,
            vehicleId: (int) $this->vehicle->id,
            startsOn: '2026-08-10',
            endsOn: '2026-08-12',
            source: \App\Domain\Bookings\Enums\BookingSource::AdminManual,
            initialStatus: $status,
            pickupTime: $pickupTime,
            returnTime: $returnTime,
        ), BookingActor::admin((int) $this->admin->id));
    }

    private function bindProcessor(?Closure $handler = null): object
    {
        $processor = new class($handler) implements GoogleCalendarOperationProcessor
        {
            /** @var list<GoogleCalendarOperationRequest> */
            public array $requests = [];

            public function __construct(public ?Closure $handler) {}

            public function process(GoogleCalendarOperationRequest $request, GoogleCalendarOperationContext $context): GoogleCalendarOperationResult
            {
                $this->requests[] = $request;
                if ($this->handler instanceof Closure) {
                    $result = ($this->handler)($request, $context);
                    if ($result instanceof GoogleCalendarOperationResult) {
                        return $result;
                    }
                }

                return new GoogleCalendarOperationResult($request->googleEventId);
            }
        };
        $this->app->instance(GoogleCalendarOperationProcessor::class, $processor);

        return $processor;
    }
}
