<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\ChangeBookingDatesData;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Integrations\Bot\Services\TelegramCustomerService;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarEventType;
use App\Domain\Integrations\GoogleCalendar\Enums\GoogleCalendarOperation;
use App\Domain\Pricing\Enums\PricingSeasonKey as DomainPricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Vehicles\Services\VehicleCatalogService;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarOutbox;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleCalendarBookingLifecycleTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00:00', 'Asia/Bangkok'));
        $this->seed();
        $this->bookings = app(BookingService::class);
        $this->customer = Customer::factory()->create();
        $this->vehicle = $this->makeVehicle();
        $this->admin = User::factory()->create();
        $this->addOneDayPrice($this->vehicle);
    }

    public function test_pending_booking_creation_queues_both_calendar_events(): void
    {
        $booking = $this->createBooking();

        $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Upsert, 1);
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Upsert, 1);
    }

    public function test_direct_approved_creation_queues_both_calendar_events(): void
    {
        $booking = $this->createBooking(BookingStatus::Approved);

        $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Upsert, 1);
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Upsert, 1);
    }

    public function test_pending_to_approved_queues_both_updates(): void
    {
        $booking = $this->createBooking();
        $this->bookings->approve($booking, BookingActor::admin($this->admin->id), 'approve-request');

        $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Upsert, 2);
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Upsert, 2);
    }

    public function test_approved_to_active_does_not_queue_calendar_operations(): void
    {
        $booking = $this->createBooking(BookingStatus::Approved);
        $this->bookings->activate($booking, BookingActor::admin($this->admin->id), 'activate-request');

        $this->assertSame(2, GoogleCalendarOutbox::query()->count());
        $this->assertSame(1, (int) $this->revision($booking, GoogleCalendarEventType::Pickup));
        $this->assertSame(1, (int) $this->revision($booking, GoogleCalendarEventType::ReturnEvent));
    }

    #[DataProvider('dateChangeCases')]
    public function test_date_change_queues_only_events_whose_date_or_time_changed(
        string $startsOn,
        string $endsOn,
        string $pickupTime,
        string $returnTime,
        array $changedTypes,
    ): void {
        $booking = $this->createBooking();
        $this->bookings->changeDates(
            $booking,
            new ChangeBookingDatesData($startsOn, $endsOn, $pickupTime, $returnTime),
            BookingActor::admin($this->admin->id),
            'date-change-request',
        );

        $expectedCount = 2 + count($changedTypes);
        $this->assertSame($expectedCount, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
        foreach ([GoogleCalendarEventType::Pickup, GoogleCalendarEventType::ReturnEvent] as $eventType) {
            $expectedRevision = in_array($eventType->value, $changedTypes, true) ? 2 : 1;
            $this->assertSame($expectedRevision, (int) $this->revision($booking, $eventType));
        }
    }

    /** @return iterable<string, array{string, string, string, string, list<string>}> */
    public static function dateChangeCases(): iterable
    {
        yield 'pickup only' => ['2026-08-11', '2026-08-12', '11:00', '12:00', ['pickup']];
        yield 'return only' => ['2026-08-10', '2026-08-13', '10:00', '13:00', ['return']];
        yield 'both events' => ['2026-08-11', '2026-08-13', '11:00', '13:00', ['pickup', 'return']];
    }

    public function test_booking_contact_name_change_updates_both_mapped_events(): void
    {
        $booking = $this->createBooking();
        app(\App\Domain\Bookings\Services\BookingDetailsService::class)->update(
            $booking,
            $this->bookingDetails('Booking-specific name'),
            $this->admin,
            'contact-name-request',
        );

        $this->assertSame('Booking-specific name', $booking->fresh()->contact_name);
        $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::Pickup));
        $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::ReturnEvent));
        $this->assertSame(4, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    public function test_customer_name_change_updates_only_bookings_with_existing_calendar_mappings(): void
    {
        $first = $this->createBooking();
        $second = $this->createBooking();
        $unmapped = Booking::factory()->create([
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => BookingStatus::Process,
        ]);

        app(TelegramCustomerService::class)->update($this->customer, ['name' => 'Renamed Customer'], 'customer-name-request');

        foreach ([$first, $second] as $booking) {
            $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::Pickup));
            $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::ReturnEvent));
        }
        $this->assertSame(0, GoogleCalendarEvent::query()->where('booking_id', $unmapped->id)->count());
        $this->assertSame(8, GoogleCalendarOutbox::query()->count());
    }

    public function test_vehicle_rename_updates_only_bookings_with_existing_calendar_mappings(): void
    {
        $booking = $this->createBooking();
        $unmapped = Booking::factory()->create([
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => BookingStatus::Process,
        ]);

        app(VehicleCatalogService::class)->updateVehicle($this->vehicle, [
            'external_code' => $this->vehicle->external_code,
            'type' => (string) $this->vehicle->getRawOriginal('type'),
            'category_id' => $this->vehicle->category_id,
            'name' => 'Renamed Vehicle',
            'inventory_code' => $this->vehicle->inventory_code,
            'year' => $this->vehicle->year,
            'description' => $this->vehicle->description,
            'description_ru' => $this->vehicle->description_ru,
            'description_ua' => $this->vehicle->description_ua,
            'characteristics_text' => $this->vehicle->characteristics_text,
            'emoji' => $this->vehicle->emoji,
            'is_active' => true,
            'is_visible_for_booking' => false,
            'sort_order' => $this->vehicle->sort_order,
        ], 'vehicle-name-request');

        $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::Pickup));
        $this->assertSame(2, (int) $this->revision($booking, GoogleCalendarEventType::ReturnEvent));
        $this->assertSame(0, GoogleCalendarEvent::query()->where('booking_id', $unmapped->id)->count());
        $this->assertSame(4, GoogleCalendarOutbox::query()->count());
    }

    public function test_admin_cancellation_queues_both_deletions(): void
    {
        $booking = $this->createBooking();
        $this->bookings->cancelByAdmin($booking, BookingActor::admin($this->admin->id), 'Customer request', 'admin-cancel-request');

        $this->assertDeletedEventPair($booking);
    }

    public function test_client_cancellation_queues_both_deletions(): void
    {
        $booking = $this->createBooking(BookingStatus::Approved);
        $this->bookings->cancelByClient(
            $booking,
            BookingActor::customer($this->customer->id),
            requestId: 'client-cancel-request',
        );

        $this->assertDeletedEventPair($booking);
    }

    public function test_expiry_queues_both_deletions(): void
    {
        $booking = $this->createBooking();
        DB::table('bookings')->where('id', $booking->id)->update(['pending_expires_at' => now()->subMinute()]);

        $this->bookings->expire($booking, requestId: 'expiry-request');

        $this->assertDeletedEventPair($booking);
    }

    public function test_competing_pending_booking_cancelled_unavailable_queues_both_deletions(): void
    {
        $winner = $this->createBooking();
        $competitor = $this->createBooking();

        $this->bookings->approve($winner, BookingActor::admin($this->admin->id), 'winner-approval');

        $this->assertSame(BookingStatus::CancelledUnavailable, $competitor->fresh()->bookingStatus());
        $this->assertDeletedEventPair($competitor);
    }

    public function test_no_show_keeps_and_updates_both_events(): void
    {
        $booking = $this->createBooking(BookingStatus::Approved);
        $this->bookings->markNoShow(
            $booking,
            BookingActor::admin($this->admin->id),
            at: CarbonImmutable::parse('2026-08-10 10:00:00', 'Asia/Bangkok'),
            requestId: 'no-show-request',
        );

        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->bookingStatus());
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Upsert, 2);
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Upsert, 2);
        // Creation queued revision 1 for both events; NO SHOW queues revision 2 for both.
        $this->assertSame(4, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    public function test_completed_booking_keeps_both_events_without_deletion(): void
    {
        $booking = $this->createBooking(BookingStatus::Approved);
        $active = $this->bookings->activate($booking, BookingActor::admin($this->admin->id));
        $this->bookings->complete($active, BookingActor::admin($this->admin->id));

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->bookingStatus());
        $this->assertSame(2, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
        $this->assertDatabaseMissing('google_calendar_outbox', [
            'booking_id' => $booking->id,
            'operation' => GoogleCalendarOperation::Delete->value,
        ]);
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)->count());
    }

    public function test_creation_is_independent_of_booking_source(): void
    {
        foreach (BookingSource::cases() as $source) {
            $booking = $this->createBooking(source: $source);
            $this->assertSame($source, $booking->source);
            $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Upsert, 1);
            $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Upsert, 1);
        }

        $this->assertSame(count(BookingSource::cases()) * 2, GoogleCalendarOutbox::query()->count());
    }

    public function test_booking_creation_and_calendar_outbox_roll_back_together(): void
    {
        try {
            DB::transaction(function (): void {
                $this->createBooking();
                throw new RuntimeException('Force outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer transaction rollback.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('google_calendar_events', 0);
        $this->assertDatabaseCount('google_calendar_outbox', 0);
    }

    public function test_existing_booking_and_calendar_updates_roll_back_together(): void
    {
        $booking = $this->createBooking();

        try {
            DB::transaction(function () use ($booking): void {
                $this->bookings->changeDates(
                    $booking,
                    new ChangeBookingDatesData('2026-08-11', '2026-08-12', '11:00', '12:00'),
                    BookingActor::admin($this->admin->id),
                );
                throw new RuntimeException('Force outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force outer transaction rollback.', $exception->getMessage());
        }

        $this->assertSame('2026-08-10', $booking->fresh()->starts_on->format('Y-m-d'));
        $this->assertSame(1, (int) $this->revision($booking, GoogleCalendarEventType::Pickup));
        $this->assertSame(1, (int) $this->revision($booking, GoogleCalendarEventType::ReturnEvent));
        $this->assertSame(2, GoogleCalendarOutbox::query()->where('booking_id', $booking->id)->count());
    }

    private function createBooking(
        BookingStatus $status = BookingStatus::Pending,
        BookingSource $source = BookingSource::AdminManual,
    ): Booking {
        return $this->bookings->create(
            new CreateBookingData(
                customerId: (int) $this->customer->id,
                vehicleId: (int) $this->vehicle->id,
                startsOn: '2026-08-10',
                endsOn: '2026-08-12',
                source: $source,
                initialStatus: $status,
                pickupTime: '10:00',
                returnTime: '12:00',
            ),
            BookingActor::admin((int) $this->admin->id),
        );
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => false]);
    }

    private function addOneDayPrice(Vehicle $vehicle): void
    {
        $season = PricingSeason::query()->where('key', DomainPricingSeasonKey::Low->value)->firstOrFail();
        VehiclePriceTier::query()->create([
            'vehicle_id' => $vehicle->id,
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

    private function bookingDetails(string $contactName): array
    {
        return [
            'expected_details_version' => 1,
            'contact_name' => $contactName,
            'contact_phone' => '',
            'contact_telegram' => '',
            'payments' => [
                'prepayment' => ['amount' => '1500', 'currency' => 'THB', 'received' => false],
                'deposit' => ['amount' => null, 'currency' => 'THB', 'received' => false],
            ],
        ];
    }

    private function revision(Booking $booking, GoogleCalendarEventType $eventType): ?int
    {
        $revision = GoogleCalendarEvent::query()
            ->where('booking_id', $booking->id)
            ->where('event_type', $eventType->value)
            ->value('last_requested_revision');

        return $revision === null ? null : (int) $revision;
    }

    private function assertQueuedEvent(
        Booking $booking,
        GoogleCalendarEventType $eventType,
        GoogleCalendarOperation $operation,
        int $revision,
    ): void {
        $this->assertDatabaseHas('google_calendar_outbox', [
            'booking_id' => $booking->id,
            'event_type' => $eventType->value,
            'operation' => $operation->value,
            'event_revision' => $revision,
        ]);
    }

    private function assertDeletedEventPair(Booking $booking): void
    {
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::Pickup, GoogleCalendarOperation::Delete, 2);
        $this->assertQueuedEvent($booking, GoogleCalendarEventType::ReturnEvent, GoogleCalendarOperation::Delete, 2);
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(2, GoogleCalendarEvent::query()->where('booking_id', $booking->id)
            ->where('sync_status', 'delete_pending')->count());
    }
}
