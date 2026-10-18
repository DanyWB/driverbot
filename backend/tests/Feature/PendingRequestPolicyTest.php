<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\ChangeBookingDatesData;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Bookings\Services\PendingRequestPolicy;
use App\Domain\Notifications\Exceptions\NotificationNotApplicableException;
use App\Domain\Notifications\Services\NotificationOutboxService;
use App\Domain\Notifications\Services\TelegramNotificationRenderer;
use App\Domain\Pricing\Services\VehiclePricingService;
use App\Models\AdminTelegramBinding;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PendingRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-01 10:00', 'Asia/Bangkok'));
        $this->seed();
        $this->vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        $this->admin = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $prices = $enabled = [];
        foreach (['high', 'middle', 'low'] as $season) {
            foreach (['1d', '7d', '14d', '21d', 'month'] as $tier) {
                $prices[$season][$tier] = 1500;
                $enabled[$season][$tier] = true;
            }
        }
        app(VehiclePricingService::class)->replaceMatrix($this->vehicle, $prices, $enabled);
    }

    public function test_competition_only_closes_the_same_vehicle_overlapping_requests(): void
    {
        $first = $this->request();
        $second = $this->request();
        $later = $this->request('2026-08-15', '2026-08-17');
        $this->assertFalse($first->occupancy->blocks_availability);
        $this->assertSame(2, $first->request_policy_version);
        $this->assertSame('2026-08-03 03:00', $first->pendingExpiresAt()->utc()->format('Y-m-d H:i'));
        app(BookingService::class)->approve($first, BookingActor::admin($this->admin->id));
        $this->assertSame(BookingStatus::CancelledUnavailable, $second->refresh()->bookingStatus());
        $this->assertSame(BookingStatus::Pending, $later->refresh()->bookingStatus());
        $this->assertTrue($first->refresh()->occupancy->blocks_availability);
        $this->assertNull($second->occupancy);
        $this->assertDatabaseHas('booking_status_history', ['booking_id' => $second->id, 'to_status' => 'cancelled_unavailable']);
        $this->assertSame(0, NotificationOutbox::query()->where('deduplication_key', 'like', "booking:{$second->public_id}:pending-reminder:%")
            ->where('status', NotificationOutbox::STATUS_PENDING)->count());
    }

    public function test_deadline_is_noon_or_explicit_pickup_and_cannot_be_approved_at_the_boundary(): void
    {
        $booking = $this->request('2026-08-01', '2026-08-02');
        $this->assertSame('2026-08-01 05:00', $booking->pendingExpiresAt()->utc()->format('Y-m-d H:i'));
        $this->travelTo($booking->pendingExpiresAt());
        try {
            app(BookingService::class)->approve($booking, BookingActor::admin($this->admin->id));
            $this->fail('Approval after deadline succeeded.');
        } catch (BookingException $exception) {
            $this->assertSame('booking_request_expired', $exception->errorCode);
        }
        app(BookingService::class)->expire($booking);
        $this->assertSame(BookingStatus::Expired, $booking->refresh()->bookingStatus());
        $this->assertNull($booking->occupancy);
        $policy = app(PendingRequestPolicy::class);
        $this->assertSame('2026-08-02 02:30', $policy->deadline(now(), '2026-08-02', '09:30')->utc()->format('Y-m-d H:i'));
    }

    public function test_evening_boundary_has_no_duplicate_and_next_day_has_two_slots(): void
    {
        $policy = app(PendingRequestPolicy::class);
        foreach (['17:59' => 4, '18:00' => 3, '18:01' => 4] as $time => $count) {
            $created = CarbonImmutable::parse("2026-08-01 {$time}", 'Asia/Bangkok');
            $slots = $policy->reminderSlots($created, $created->addHours(48));
            $this->assertCount($count, $slots, $time);
            $this->assertTrue(collect($slots)->every(fn ($slot) => $slot->gt($created) && $slot->lt($created->addHours(48))));
        }
    }

    public function test_date_changes_do_not_restart_the_submission_clock(): void
    {
        $booking = $this->request();
        $deadline = $booking->pendingExpiresAt()->toIso8601String();
        $this->travelTo(now()->addDay());
        $updated = app(BookingService::class)->changeDates($booking,
            new ChangeBookingDatesData('2026-08-20', '2026-08-23'), BookingActor::admin($this->admin->id));
        $this->assertSame($deadline, $updated->pendingExpiresAt()->toIso8601String());
        $this->assertFalse($updated->occupancy->blocks_availability);
    }

    public function test_other_vehicle_is_not_cancelled_and_rollback_preserves_competitors(): void
    {
        $winner = $this->request();
        $competitor = $this->request();
        $original = $this->vehicle;
        $this->vehicle = $original->replicate();
        $this->vehicle->external_code = 'test-other';
        $this->vehicle->save();
        foreach ($original->priceTiers as $tier) {
            $copy = $tier->replicate();
            $copy->vehicle_id = $this->vehicle->id;
            $copy->save();
        }
        $other = $this->request();
        DB::beginTransaction();
        app(BookingService::class)->approve($winner, BookingActor::admin($this->admin->id));
        $this->assertSame(BookingStatus::Pending, $other->refresh()->bookingStatus());
        DB::rollBack();
        $this->assertSame(BookingStatus::Pending, $competitor->refresh()->bookingStatus());
        $this->assertSame(BookingStatus::Pending, $winner->refresh()->bookingStatus());
        $this->assertFalse($winner->occupancy->blocks_availability);
    }

    public function test_scheduling_is_idempotent_and_stale_delivery_is_discarded(): void
    {
        $booking = $this->request();
        $outbox = app(NotificationOutboxService::class);
        $before = NotificationOutbox::query()->count();
        $outbox->schedulePendingReminders($booking);
        $this->assertSame($before, NotificationOutbox::query()->count());
        $notification = NotificationOutbox::query()->where('event_type', 'booking.pending_reminder')->orderBy('available_at')->firstOrFail();
        $this->travelTo(CarbonImmutable::instance($notification->available_at)->addHours(2));
        $this->expectException(NotificationNotApplicableException::class);
        app(TelegramNotificationRenderer::class)->render($notification);
    }

    public function test_reconciliation_backfills_pending_reminders_for_a_new_admin_binding_without_duplicates(): void
    {
        AdminTelegramBinding::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'generation' => 1,
            'disconnected_at' => now(),
        ]);
        $booking = $this->request();
        $this->assertSame(0, NotificationOutbox::query()
            ->where('event_type', 'booking.pending_reminder')
            ->count());

        $binding = AdminTelegramBinding::query()->create([
            'admin_user_id' => User::factory()->create()->id,
            'telegram_user_id' => '44556677',
            'telegram_chat_id' => '44556677',
            'locale' => 'en',
            'generation' => 1,
            'connected_at' => now(),
        ]);

        $this->artisan('bookings:reconcile-reminders')->assertSuccessful();
        $this->artisan('bookings:reconcile-reminders')->assertSuccessful();

        $reminders = NotificationOutbox::query()
            ->where('event_type', 'booking.pending_reminder')
            ->where('recipient', $binding->recipientKey())
            ->orderBy('available_at')
            ->get();
        $this->assertSame([
            '2026-08-01 18:00',
            '2026-08-02 12:00',
            '2026-08-02 18:00',
        ], $reminders->map(fn (NotificationOutbox $notification): string => $notification->available_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i')
        )->all());
        $this->assertSame(3, $reminders->pluck('deduplication_key')->unique()->count());
        $this->assertTrue($reminders->every(fn (NotificationOutbox $notification): bool => $notification->available_at->isAfter(now())
                && $notification->available_at->isBefore($booking->pendingExpiresAt())
        ));
    }

    private function request(string $start = '2026-08-10', string $end = '2026-08-12'): Booking
    {
        return app(BookingService::class)->create(new CreateBookingData(
            customerId: $this->customer->id, vehicleId: $this->vehicle->id,
            startsOn: $start, endsOn: $end, source: BookingSource::AdminManual,
        ), BookingActor::admin($this->admin->id));
    }
}
