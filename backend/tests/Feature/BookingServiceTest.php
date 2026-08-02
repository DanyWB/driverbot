<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\ChangeBookingDatesData;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\BookingStatusHistory;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\NotificationOutbox;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOccupancy;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookings;

    private Customer $customer;

    private Vehicle $vehicle;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->bookings = app(BookingService::class);
        $this->customer = Customer::factory()->create();
        $this->vehicle = Vehicle::factory()->create([
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        $this->admin = User::factory()->create();
        CustomerIdentity::query()->create([
            'customer_id' => $this->customer->id,
            'provider' => IdentityProvider::Telegram,
            'external_id' => '100500',
        ]);
        $this->addPrice($this->vehicle, PricingSeasonKey::Low, PricingTier::OneDay, 300);
    }

    public function test_pending_creation_is_atomic_and_records_all_operational_artifacts(): void
    {
        $booking = $this->createPending();

        $this->assertSame(BookingStatus::Pending, $booking->bookingStatus());
        $this->assertNotNull($booking->pendingExpiresAt());
        $this->assertSame(900, $booking->priceSnapshots()->firstOrFail()->final_total);
        $occupancy = VehicleOccupancy::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('2026-08-10', $occupancy->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-12', $occupancy->ends_on->format('Y-m-d'));
        $this->assertTrue($occupancy->blocks_availability);
        $this->assertSame(1, BookingStatusHistory::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(2, AuditLog::query()->where('subject_id', $booking->public_id)->count());
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'booking.pending', 'channel' => 'telegram']);
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'booking.pending', 'channel' => 'internal']);
    }

    public function test_delivery_requires_an_address_at_the_domain_boundary(): void
    {
        try {
            $this->bookings->create(
                new CreateBookingData(
                    customerId: $this->customer->id,
                    vehicleId: $this->vehicle->id,
                    startsOn: '2026-08-10',
                    endsOn: '2026-08-12',
                    source: BookingSource::AdminManual,
                    deliveryRequired: true,
                    deliveryAddress: '   ',
                ),
                BookingActor::admin($this->admin->id),
            );
            $this->fail('A delivery booking without an address was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('delivery_address_required', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_domain_rejects_a_rental_shorter_than_one_hour(): void
    {
        try {
            $this->bookings->create(
                new CreateBookingData(
                    customerId: $this->customer->id,
                    vehicleId: $this->vehicle->id,
                    startsOn: '2026-08-10',
                    endsOn: '2026-08-10',
                    source: BookingSource::AdminManual,
                    pickupTime: '10:00',
                    returnTime: '10:30',
                ),
                BookingActor::admin($this->admin->id),
            );
            $this->fail('A rental shorter than one hour was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('invalid_booking_time_range', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_conflict_returns_409_and_admin_cancellation_releases_dates(): void
    {
        $first = $this->createPending();
        $otherCustomer = Customer::factory()->create();

        try {
            $this->createPending(customer: $otherCustomer);
            $this->fail('An overlapping booking was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('vehicle_unavailable', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }

        $cancelled = $this->bookings->cancelByAdmin(
            $first,
            BookingActor::admin($this->admin->id),
            'Vehicle requires inspection',
        );

        $this->assertSame(BookingStatus::Cancelled, $cancelled->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $first->id]);
        $this->assertSame(BookingStatus::Pending, $this->createPending(customer: $otherCustomer)->bookingStatus());
    }

    public function test_date_change_updates_occupancy_and_creates_a_new_automatic_snapshot(): void
    {
        $booking = $this->createPending();

        $changed = $this->bookings->changeDates(
            $booking,
            new ChangeBookingDatesData('2026-08-15', '2026-08-18', '09:30'),
            BookingActor::admin($this->admin->id),
        );

        $this->assertSame('2026-08-15', $changed->starts_on->format('Y-m-d'));
        $this->assertSame(2, BookingPriceSnapshot::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1200, $changed->priceSnapshots()->firstOrFail()->final_total);
        $occupancy = VehicleOccupancy::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('2026-08-15', $occupancy->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-18', $occupancy->ends_on->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $booking->public_id, 'action' => 'booking.dates_changed']);
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'booking.dates_changed']);
    }

    public function test_client_cancellation_enforces_the_24_hour_boundary(): void
    {
        $booking = $this->createApproved('2026-08-20', '2026-08-20', '10:00');

        try {
            $this->bookings->cancelByClient(
                $booking,
                BookingActor::customer($this->customer->id),
                at: CarbonImmutable::parse('2026-08-19 10:01:00', 'Asia/Bangkok'),
            );
            $this->fail('A late client cancellation was accepted.');
        } catch (BookingException $exception) {
            $this->assertSame('client_cancellation_requires_manager', $exception->errorCode);
            $this->assertSame(BookingStatus::Approved, $booking->fresh()->bookingStatus());
        }

        $cancelled = $this->bookings->cancelByClient(
            $booking,
            BookingActor::customer($this->customer->id),
            at: CarbonImmutable::parse('2026-08-19 10:00:00', 'Asia/Bangkok'),
        );

        $this->assertSame(BookingStatus::CancelledByClient, $cancelled->bookingStatus());
        $this->assertDatabaseHas('notification_outbox', ['event_type' => 'booking.cancelled_by_client', 'channel' => 'internal']);
    }

    public function test_no_show_is_only_available_for_approved_bookings_after_start(): void
    {
        $booking = $this->createApproved('2026-08-20', '2026-08-20', '10:00');

        try {
            $this->bookings->markNoShow(
                $booking,
                BookingActor::admin($this->admin->id),
                at: CarbonImmutable::parse('2026-08-20 09:59:00', 'Asia/Bangkok'),
            );
            $this->fail('No-show was accepted before rental start.');
        } catch (BookingException $exception) {
            $this->assertSame('no_show_too_early', $exception->errorCode);
        }

        $noShow = $this->bookings->markNoShow(
            $booking,
            BookingActor::admin($this->admin->id),
            'Client did not arrive',
            CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Bangkok'),
        );

        $this->assertSame(BookingStatus::NoShow, $noShow->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $booking->id]);

        try {
            $this->bookings->markNoShow($noShow, BookingActor::admin($this->admin->id));
            $this->fail('No-show was accepted from a terminal status.');
        } catch (BookingException $exception) {
            $this->assertSame('invalid_booking_transition', $exception->errorCode);
        }
    }

    public function test_approve_activate_complete_lifecycle_keeps_then_releases_occupancy(): void
    {
        $booking = $this->createPending();
        $approved = $this->bookings->approve($booking, BookingActor::admin($this->admin->id));
        $active = $this->bookings->activate($approved, BookingActor::admin($this->admin->id));

        $this->assertSame(BookingStatus::Active, $active->bookingStatus());
        $this->assertDatabaseHas('vehicle_occupancies', ['booking_id' => $booking->id]);

        $completed = $this->bookings->complete($active, BookingActor::admin($this->admin->id));

        $this->assertSame(BookingStatus::Completed, $completed->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $booking->id]);
        $this->assertSame(4, BookingStatusHistory::query()->where('booking_id', $booking->id)->count());
    }

    public function test_approved_booking_reminders_are_versioned_reconciled_and_discarded(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-22 12:00:00', 'Asia/Bangkok'));

        try {
            $booking = $this->createApproved('2026-08-20', '2026-08-22', '10:00');
            $this->assertSame(1, $booking->reminder_version);
            $this->assertSame(2, NotificationOutbox::query()
                ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:v1:%")
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->count());
            $dayReminder = NotificationOutbox::query()
                ->where('deduplication_key', "booking:{$booking->public_id}:reminder:v1:pickup_day")
                ->firstOrFail();
            $hourReminder = NotificationOutbox::query()
                ->where('deduplication_key', "booking:{$booking->public_id}:reminder:v1:pickup_one_hour")
                ->firstOrFail();
            $this->assertSame('2026-08-20 08:00', $dayReminder->available_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i'));
            $this->assertSame('2026-08-20 09:00', $hourReminder->available_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i'));

            $this->artisan('bookings:reconcile-reminders')->assertSuccessful();
            $this->artisan('bookings:reconcile-reminders')->assertSuccessful();
            $this->assertSame(2, NotificationOutbox::query()
                ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:v1:%")
                ->count());

            $changed = $this->bookings->changeDates(
                $booking,
                new ChangeBookingDatesData('2026-08-25', '2026-08-27', '11:00'),
                BookingActor::admin($this->admin->id),
            );
            $this->assertSame(2, $changed->reminder_version);
            $this->assertSame(2, NotificationOutbox::query()
                ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:v1:%")
                ->where('status', NotificationOutbox::STATUS_DISCARDED)
                ->count());
            $this->assertSame(2, NotificationOutbox::query()
                ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:v2:%")
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->count());

            $this->bookings->cancelByAdmin($changed, BookingActor::admin($this->admin->id), 'Customer request');
            $this->assertSame(2, NotificationOutbox::query()
                ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:v2:%")
                ->where('status', NotificationOutbox::STATUS_DISCARDED)
                ->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_process_submission_calculates_price_and_starts_blocking(): void
    {
        $process = Booking::factory()->create([
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-12',
        ]);

        $pending = $this->bookings->submitProcess($process, BookingActor::customer($this->customer->id));

        $this->assertSame(BookingStatus::Pending, $pending->bookingStatus());
        $this->assertDatabaseHas('vehicle_occupancies', ['booking_id' => $process->id]);
        $this->assertDatabaseHas('booking_price_snapshots', ['booking_id' => $process->id, 'version' => 1]);
    }

    public function test_expiry_command_is_idempotent_and_releases_occupancy(): void
    {
        $booking = $this->createPending();
        $booking->forceFill(['pending_expires_at' => now()->subMinute()])->save();

        $this->artisan('bookings:expire-pending')->assertSuccessful();
        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $this->assertSame(BookingStatus::Expired, $booking->fresh()->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $booking->id]);
        $this->assertSame(1, NotificationOutbox::query()->where('event_type', 'booking.expired')->count());
    }

    public function test_direct_status_mutation_is_rejected(): void
    {
        $booking = $this->createPending();

        $this->expectException(LogicException::class);
        $booking->forceFill(['status' => BookingStatus::Approved])->save();
    }

    public function test_direct_creation_of_a_blocking_booking_is_rejected(): void
    {
        $this->expectException(LogicException::class);

        Booking::factory()->create([
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => BookingStatus::Pending,
        ]);
    }

    public function test_customer_cannot_create_a_booking_for_another_customer(): void
    {
        $otherCustomer = Customer::factory()->create();

        try {
            $this->createPending(customer: $otherCustomer, actor: BookingActor::customer($this->customer->id));
            $this->fail('A customer created a booking for another customer.');
        } catch (BookingException $exception) {
            $this->assertSame('booking_customer_mismatch', $exception->errorCode);
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    private function createPending(?Customer $customer = null, ?BookingActor $actor = null): Booking
    {
        $customer ??= $this->customer;
        $actor ??= BookingActor::customer($customer->id);

        return $this->bookings->create(
            new CreateBookingData(
                customerId: $customer->id,
                vehicleId: $this->vehicle->id,
                startsOn: '2026-08-10',
                endsOn: '2026-08-12',
                source: BookingSource::Telegram,
                termsAcceptedAt: now(),
                termsVersion: (string) config('business.terms_version'),
            ),
            $actor,
        );
    }

    private function createApproved(string $startsOn, string $endsOn, ?string $pickupTime = null): Booking
    {
        return $this->bookings->create(
            new CreateBookingData(
                customerId: $this->customer->id,
                vehicleId: $this->vehicle->id,
                startsOn: $startsOn,
                endsOn: $endsOn,
                source: BookingSource::AdminManual,
                initialStatus: BookingStatus::Approved,
                pickupTime: $pickupTime,
            ),
            BookingActor::admin($this->admin->id),
        );
    }

    private function addPrice(Vehicle $vehicle, PricingSeasonKey $seasonKey, PricingTier $tier, int $packageTotal): void
    {
        $season = PricingSeason::query()->where('key', $seasonKey->value)->firstOrFail();

        VehiclePriceTier::query()->create([
            'vehicle_id' => $vehicle->id,
            'pricing_season_id' => $season->id,
            'tier_key' => $tier,
            'min_days' => $tier->minimumDays(),
            'max_days' => $tier->maximumDays(),
            'anchor_days' => $tier->anchorDays(),
            'package_total' => $packageTotal,
            'daily_rate' => (string) BigDecimal::of($packageTotal)->dividedBy($tier->anchorDays(), 6, RoundingMode::HalfUp),
            'currency' => 'THB',
            'is_active' => true,
        ]);
    }
}
