<?php

namespace Tests\Feature;

use App\Domain\Availability\Enums\OccupancyType;
use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBookingManagementTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookings;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->bookings = app(BookingService::class);
        $this->admin = User::factory()->create();
        $this->customer = Customer::factory()->create(['name' => 'Alex Rider']);
        $this->vehicle = Vehicle::factory()->create([
            'name' => 'Honda Click 160',
            'is_active' => true,
            'is_visible_for_booking' => true,
        ]);
        CustomerIdentity::query()->create([
            'customer_id' => $this->customer->id,
            'provider' => IdentityProvider::Telegram,
            'external_id' => 'telegram-100500',
        ]);
        $this->addPrice($this->vehicle, PricingSeasonKey::Low, PricingTier::OneDay, 300);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_booking_admin_routes_require_authentication(): void
    {
        $booking = $this->createBooking();

        $this->get(route('bookings.index'))->assertRedirect(route('login'));
        $this->get(route('bookings.create'))->assertRedirect(route('login'));
        $this->get(route('bookings.show', $booking))->assertRedirect(route('login'));
        $this->get(route('bookings.customers.search', ['search' => 'Alex']))->assertRedirect(route('login'));
        $this->post(route('bookings.approve', $booking))->assertRedirect(route('login'));
    }

    public function test_customer_lookup_searches_all_customers_by_normalized_contact(): void
    {
        $this->customer->contacts()->create([
            'type' => 'phone',
            'value' => '+66 81 234 5678',
            'normalized_value' => '+66812345678',
            'is_primary' => true,
        ]);
        Customer::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->getJson(route('bookings.customers.search', ['search' => '812345678']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->customer->id)
            ->assertJsonPath('data.0.name', 'Alex Rider')
            ->assertJsonPath('data.0.contacts.0', '+66 81 234 5678');
    }

    public function test_dashboard_ignores_occupancy_of_inactive_vehicles(): void
    {
        $today = now((string) config('business.timezone'))->toDateString();
        $inactive = Vehicle::factory()->create(['is_active' => false]);
        VehicleOccupancy::query()->create([
            'vehicle_id' => $inactive->id,
            'type' => OccupancyType::Maintenance,
            'starts_on' => $today,
            'ends_on' => $today,
            'blocks_availability' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.active_vehicles', 1)
                ->where('summary.available_vehicles', 1));
    }

    public function test_index_filters_on_the_server_and_show_exposes_operational_detail(): void
    {
        $booking = $this->createBooking();
        $archivedCustomer = Customer::factory()->create(['name' => 'Archived Customer']);
        $archived = $this->createBooking($archivedCustomer, startsOn: '2026-08-20', endsOn: '2026-08-20');
        $archived = $this->bookings->approve($archived, BookingActor::admin($this->admin->id));
        $archived = $this->bookings->activate($archived, BookingActor::admin($this->admin->id));
        $this->bookings->complete($archived, BookingActor::admin($this->admin->id));

        $this->actingAs($this->admin)
            ->get(route('bookings.index', [
                'scope' => 'all',
                'search' => 'Alex Rider',
                'per_page' => 15,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Index')
                ->where('bookings.total', 1)
                ->where('bookings.data.0.public_id', $booking->public_id)
                ->where('filters.search', 'Alex Rider')
                ->where('filters.per_page', 15));

        $this->actingAs($this->admin)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Show')
                ->where('booking.public_id', $booking->public_id)
                ->where('booking.customer.name', 'Alex Rider')
                ->where('booking.vehicle.name', 'Honda Click 160')
                ->where('booking.actions.approve', true)
                ->has('booking.price_snapshots', 1)
                ->has('booking.status_history', 1));
    }

    public function test_admin_can_create_a_manual_booking_with_customer_contacts_and_final_price(): void
    {
        $response = $this->actingAs($this->admin)->post(route('bookings.store'), [
            ...$this->manualBookingPayload(),
            'customer_name' => 'Phone Customer',
            'phone' => '+66 81 234 5678',
            'telegram_username' => '@phone_customer',
            'manual_total' => 800,
            'override_reason' => 'Agreed package price',
        ]);

        $booking = Booking::query()->sole();
        $customer = Customer::query()->where('name', 'Phone Customer')->firstOrFail();

        $response->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(BookingStatus::Approved, $booking->bookingStatus());
        $this->assertSame($this->admin->id, $booking->created_by_admin_id);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'type' => 'phone',
            'normalized_value' => '+66812345678',
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'type' => 'telegram_username',
            'normalized_value' => 'phone_customer',
        ]);
        $this->assertSame(2, BookingPriceSnapshot::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(800, $booking->priceSnapshots()->firstOrFail()->final_total);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $booking->public_id,
            'action' => 'booking.price_overridden',
            'actor_admin_id' => $this->admin->id,
        ]);
    }

    public function test_conflicting_manual_booking_rolls_back_customer_and_preserves_input(): void
    {
        $this->createBooking();
        $customerCount = Customer::query()->count();

        $response = $this->actingAs($this->admin)
            ->from(route('bookings.create'))
            ->post(route('bookings.store'), [
                ...$this->manualBookingPayload(),
                'customer_name' => 'Conflict Customer',
                'starts_on' => '2026-08-11',
                'ends_on' => '2026-08-13',
            ]);

        $response
            ->assertRedirect(route('bookings.create'))
            ->assertSessionHasErrors('booking')
            ->assertSessionHasInput('customer_name', 'Conflict Customer')
            ->assertSessionHasInput('starts_on', '2026-08-11');
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame($customerCount, Customer::query()->count());
    }

    public function test_admin_can_complete_the_main_lifecycle_and_edit_dates_and_price(): void
    {
        $booking = $this->createBooking(startsOn: '2026-08-20', endsOn: '2026-08-22');

        $this->actingAs($this->admin)
            ->post(route('bookings.approve', $booking))
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(BookingStatus::Approved, $booking->fresh()->bookingStatus());

        $this->actingAs($this->admin)
            ->patch(route('bookings.dates.update', $booking), [
                'starts_on' => '2026-08-23',
                'ends_on' => '2026-08-25',
                'pickup_time' => '09:30',
                'return_time' => '17:00',
            ])
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'starts_on' => '2026-08-23 00:00:00',
            'pickup_time' => '09:30:00',
        ]);
        $this->assertDatabaseHas('notification_outbox', [
            'event_type' => 'booking.dates_changed',
            'recipient' => 'telegram-100500',
        ]);
        $datesEvent = NotificationOutbox::query()->where('event_type', 'booking.dates_changed')->firstOrFail();
        $this->assertSame(900, $datesEvent->payload['final_total']);
        $this->assertSame('THB', $datesEvent->payload['currency']);

        $this->actingAs($this->admin)
            ->post(route('bookings.price-overrides.store', $booking), [
                'manual_total' => 700,
                'reason' => 'Returning customer discount',
            ])
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(700, $booking->priceSnapshots()->firstOrFail()->final_total);
        $this->assertDatabaseHas('notification_outbox', [
            'event_type' => 'booking.price_changed',
            'recipient' => 'telegram-100500',
        ]);

        $this->actingAs($this->admin)->post(route('bookings.activate', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Active, $booking->fresh()->bookingStatus());
        $this->actingAs($this->admin)->post(route('bookings.complete', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $booking->id]);
        $this->assertSame(4, BookingStatusHistory::query()->where('booking_id', $booking->id)->count());
    }

    public function test_admin_can_cancel_and_mark_an_approved_booking_as_no_show(): void
    {
        $cancelled = $this->createBooking(startsOn: '2026-08-15', endsOn: '2026-08-15');

        $this->actingAs($this->admin)
            ->post(route('bookings.cancel', $cancelled), ['reason' => 'Customer changed plans'])
            ->assertRedirect(route('bookings.show', $cancelled));
        $this->assertSame(BookingStatus::Cancelled, $cancelled->fresh()->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $cancelled->id]);

        $noShow = $this->createBooking(
            startsOn: '2026-08-20',
            endsOn: '2026-08-20',
            status: BookingStatus::Approved,
            pickupTime: '10:00',
        );
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 10:00:00', 'Asia/Bangkok'));

        $this->actingAs($this->admin)
            ->post(route('bookings.no-show', $noShow), ['reason' => 'Customer did not arrive'])
            ->assertRedirect(route('bookings.show', $noShow));
        $this->assertSame(BookingStatus::NoShow, $noShow->fresh()->bookingStatus());
        $this->assertDatabaseMissing('vehicle_occupancies', ['booking_id' => $noShow->id]);
    }

    public function test_quote_endpoint_reports_conflicts_but_excludes_the_current_booking(): void
    {
        $booking = $this->createBooking();
        $query = ['vehicle_id' => $this->vehicle->id, 'starts_on' => '2026-08-11', 'ends_on' => '2026-08-13'];

        $this->actingAs($this->admin)
            ->getJson(route('bookings.quote', $query))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('quote.final_total', 900);

        $this->actingAs($this->admin)
            ->getJson(route('bookings.booking-quote', [$booking, ...$query]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('quote.total_days', 3);
    }

    private function createBooking(
        ?Customer $customer = null,
        string $startsOn = '2026-08-10',
        string $endsOn = '2026-08-12',
        BookingStatus $status = BookingStatus::Pending,
        ?string $pickupTime = null,
    ): Booking {
        return $this->bookings->create(
            new CreateBookingData(
                customerId: ($customer ?? $this->customer)->id,
                vehicleId: $this->vehicle->id,
                startsOn: $startsOn,
                endsOn: $endsOn,
                source: BookingSource::AdminManual,
                initialStatus: $status,
                pickupTime: $pickupTime,
            ),
            BookingActor::admin($this->admin->id),
        );
    }

    /** @return array<string, mixed> */
    private function manualBookingPayload(): array
    {
        return [
            'customer_mode' => 'new',
            'customer_id' => null,
            'customer_name' => 'Manual Customer',
            'phone' => '',
            'telegram_username' => '',
            'vehicle_id' => $this->vehicle->id,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-12',
            'pickup_time' => '10:00',
            'return_time' => '10:00',
            'source' => BookingSource::AdminPhone->value,
            'initial_status' => BookingStatus::Approved->value,
            'client_comment' => 'Needs two helmets',
            'admin_note' => 'Confirmed by phone',
            'deposit_note' => '',
        ];
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
