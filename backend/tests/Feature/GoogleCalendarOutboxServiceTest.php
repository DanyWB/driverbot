<?php

namespace Tests\Feature;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarIdempotencyConflict;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarOutboxException;
use App\Domain\Integrations\GoogleCalendar\Services\GoogleCalendarOutboxService;
use App\Models\Booking;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleCalendarOutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarOutboxService $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outbox = app(GoogleCalendarOutboxService::class);
    }

    public function test_first_upsert_creates_mapping_and_revision_one(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        $entry = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame(1, (int) $mapping->last_requested_revision);
        $this->assertSame('pending', $mapping->sync_status);
        $this->assertSame('pickup-calendar', $mapping->calendar_id);
        $this->assertSame(GoogleCalendarOperation::Upsert->value, $entry->operation);
        $this->assertSame(1, (int) $entry->event_revision);
        $this->assertStringContainsString((string) $booking->public_id, $entry->idempotency_key);
        $this->assertSame(1, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    public function test_subsequent_upsert_allocates_a_new_revision(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        $first = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
        $second = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');

        $this->assertSame(1, (int) $first->event_revision);
        $this->assertSame(2, (int) $second->event_revision);
        $this->assertSame(2, (int) GoogleCalendarEvent::query()
            ->where('booking_id', $booking->id)
            ->where('event_type', GoogleCalendarEventType::Pickup->value)
            ->value('last_requested_revision'));
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
    }

    public function test_pickup_and_return_revisions_are_independent(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        $pickupOne = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
        $pickupTwo = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
        $returnOne = $this->outbox->upsert($booking, GoogleCalendarEventType::ReturnEvent, 'return-calendar');

        $this->assertSame(1, (int) $pickupOne->event_revision);
        $this->assertSame(2, (int) $pickupTwo->event_revision);
        $this->assertSame(1, (int) $returnOne->event_revision);
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)->count());
    }

    public function test_delete_records_new_revision_and_retains_delete_pending_tombstone(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);
        $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
        DB::table('bookings')->where('id', $booking->id)->update(['status' => BookingStatus::Cancelled->value]);

        $delete = $this->outbox->delete($booking, GoogleCalendarEventType::Pickup);
        $mapping = GoogleCalendarEvent::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame(GoogleCalendarOperation::Delete->value, $delete->operation);
        $this->assertSame(2, (int) $delete->event_revision);
        $this->assertSame(2, (int) $mapping->last_requested_revision);
        $this->assertSame('delete_pending', $mapping->sync_status);
        $this->assertDatabaseHas('google_calendar_events', ['id' => $mapping->id]);
    }

    public function test_completed_booking_cannot_be_deleted(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Completed);

        try {
            $this->outbox->delete($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
            $this->fail('Completed booking deletion was accepted.');
        } catch (GoogleCalendarOutboxException $exception) {
            $this->assertSame('booking_history_must_be_retained', $exception->reason);
        }

        $this->assertDatabaseCount('google_calendar_events', 0);
        $this->assertDatabaseCount('google_calendar_outbox', 0);
    }

    public function test_no_show_booking_cannot_be_deleted(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::NoShow);

        try {
            $this->outbox->delete($booking, GoogleCalendarEventType::ReturnEvent, 'return-calendar');
            $this->fail('No-show booking deletion was accepted.');
        } catch (GoogleCalendarOutboxException $exception) {
            $this->assertSame('booking_history_must_be_retained', $exception->reason);
        }

        $this->assertDatabaseCount('google_calendar_events', 0);
        $this->assertDatabaseCount('google_calendar_outbox', 0);
    }

    #[DataProvider('tombstoneStatuses')]
    public function test_tombstoned_mapping_rejects_upsert(string $syncStatus): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);
        GoogleCalendarEvent::query()->create([
            'booking_id' => $booking->id,
            'event_type' => GoogleCalendarEventType::Pickup->value,
            'calendar_id' => 'pickup-calendar',
            'sync_status' => $syncStatus,
            'last_requested_revision' => 1,
            'last_applied_revision' => 1,
        ]);

        try {
            $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
            $this->fail("Upsert was accepted for {$syncStatus} mapping.");
        } catch (GoogleCalendarOutboxException $exception) {
            $this->assertSame('calendar_event_tombstoned', $exception->reason);
        }

        $this->assertDatabaseCount('google_calendar_outbox', 0);
        $this->assertDatabaseHas('google_calendar_events', [
            'booking_id' => $booking->id,
            'sync_status' => $syncStatus,
            'last_requested_revision' => 1,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function tombstoneStatuses(): iterable
    {
        yield 'deletion pending' => ['delete_pending'];
        yield 'deleted' => ['deleted'];
    }

    public function test_duplicate_logical_request_returns_existing_outbox_record(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        $first = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'request-123');
        $duplicate = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'request-123');

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(1, (int) $duplicate->event_revision);
        $this->assertSame(1, (int) GoogleCalendarEvent::query()->where('booking_id', $booking->id)->value('last_requested_revision'));
        $this->assertSame(1, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    public function test_same_request_id_is_scoped_to_booking(): void
    {
        $firstBooking = $this->bookingWithStatus(BookingStatus::Pending);
        $secondBooking = $this->bookingWithStatus(BookingStatus::Pending);

        $first = $this->outbox->upsert($firstBooking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');
        $second = $this->outbox->upsert($secondBooking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
        $this->assertSame(2, GoogleCalendarOutbox::query()->count());
    }

    public function test_same_request_id_is_scoped_to_event_type(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        $pickup = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');
        $return = $this->outbox->upsert($booking, GoogleCalendarEventType::ReturnEvent, 'return-calendar', 'shared-request');

        $this->assertNotSame($pickup->id, $return->id);
        $this->assertNotSame($pickup->idempotency_key, $return->idempotency_key);
        $this->assertSame(2, GoogleCalendarOutbox::query()->count());
    }

    public function test_same_request_id_is_scoped_to_operation(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);
        $upsert = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');
        DB::table('bookings')->where('id', $booking->id)->update(['status' => BookingStatus::Cancelled->value]);

        $delete = $this->outbox->delete($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');

        $this->assertNotSame($upsert->id, $delete->id);
        $this->assertSame(GoogleCalendarOperation::Upsert->value, $upsert->operation);
        $this->assertSame(GoogleCalendarOperation::Delete->value, $delete->operation);
        $this->assertNotSame($upsert->idempotency_key, $delete->idempotency_key);
    }

    public function test_same_request_id_with_a_different_calendar_id_throws_conflict(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);
        $first = $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar', 'shared-request');

        try {
            $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'different-calendar', 'shared-request');
            $this->fail('Reusing the request ID with a different calendar ID was accepted.');
        } catch (GoogleCalendarIdempotencyConflict $exception) {
            $this->assertSame('idempotency_key_reused_with_different_parameters', $exception->reason);
        }

        $this->assertSame(1, GoogleCalendarOutbox::query()->count());
        $this->assertSame(1, (int) $first->event_revision);
        $this->assertSame('pickup-calendar', GoogleCalendarEvent::query()->sole()->calendar_id);
    }

    public function test_mapping_and_outbox_roll_back_with_the_callers_transaction(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);

        try {
            DB::transaction(function () use ($booking): void {
                $this->outbox->upsert($booking, GoogleCalendarEventType::ReturnEvent, 'return-calendar');
                throw new RuntimeException('Force outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer transaction rollback.', $exception->getMessage());
        }

        $this->assertDatabaseCount('google_calendar_events', 0);
        $this->assertDatabaseCount('google_calendar_outbox', 0);
    }

    public function test_existing_mapping_update_and_outbox_roll_back_with_the_callers_transaction(): void
    {
        $booking = $this->bookingWithStatus(BookingStatus::Pending);
        $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');

        try {
            DB::transaction(function () use ($booking): void {
                $this->outbox->upsert($booking, GoogleCalendarEventType::Pickup, 'pickup-calendar');
                throw new RuntimeException('Force outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer transaction rollback.', $exception->getMessage());
        }

        $mapping = GoogleCalendarEvent::query()->sole();
        $this->assertSame(1, (int) $mapping->last_requested_revision);
        $this->assertSame('pending', $mapping->sync_status);
        $this->assertSame(1, GoogleCalendarOutbox::query()->count());
        $this->assertDatabaseHas('google_calendar_outbox', [
            'booking_id' => $booking->id,
            'event_revision' => 1,
        ]);
    }

    private function bookingWithStatus(BookingStatus $status): Booking
    {
        $booking = Booking::factory()->create();
        DB::table('bookings')->where('id', $booking->id)->update(['status' => $status->value]);

        return Booking::query()->findOrFail($booking->id);
    }
}
