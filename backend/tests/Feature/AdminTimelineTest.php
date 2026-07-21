<?php

namespace Tests\Feature;

use App\Domain\Availability\Services\MaintenanceService;
use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Pricing\Enums\PricingSeasonKey;
use App\Domain\Pricing\Enums\PricingTier;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PricingSeason;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePriceTier;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTimelineTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookings;

    private MaintenanceService $maintenance;

    private User $admin;

    private Customer $customer;

    private Category $scooterCategory;

    private Category $carCategory;

    private Vehicle $longNameVehicle;

    private Vehicle $car;

    private Vehicle $maintenanceVehicle;

    private Vehicle $freeVehicle;

    private Vehicle $inactiveVehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->bookings = app(BookingService::class);
        $this->maintenance = app(MaintenanceService::class);
        $this->admin = User::factory()->create();
        $this->customer = Customer::factory()->create(['name' => 'Timeline Customer']);
        $this->scooterCategory = Category::factory()->create([
            'name' => 'Automatic scooters',
            'vehicle_type' => VehicleType::Scooter,
            'sort_order' => 10,
        ]);
        $this->carCategory = Category::factory()->create([
            'name' => 'Compact cars',
            'vehicle_type' => VehicleType::Car,
            'sort_order' => 20,
        ]);

        $this->longNameVehicle = $this->vehicle(
            'Honda Click 160 Special Touring Edition With Extra Long Inventory Name',
            VehicleType::Scooter,
            $this->scooterCategory,
            10,
            visible: true,
        );
        $this->car = $this->vehicle('Toyota Yaris', VehicleType::Car, $this->carCategory, 20);
        $this->maintenanceVehicle = $this->vehicle('Yamaha NMAX', VehicleType::Scooter, $this->scooterCategory, 30);
        $this->freeVehicle = $this->vehicle('Honda Scoopy', VehicleType::Scooter, $this->scooterCategory, 40, visible: true);
        $this->inactiveVehicle = $this->vehicle(
            'Archived PCX',
            VehicleType::Scooter,
            $this->scooterCategory,
            50,
            active: false,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_timeline_requires_authentication_and_defaults_to_the_business_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 14:00:00', 'Asia/Bangkok'));

        $this->get(route('timeline.index'))->assertRedirect(route('login'));

        $this->actingAs($this->admin)
            ->get(route('timeline.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('timeline/Index')
                ->where('filters.starts_on', '2026-07-01')
                ->where('filters.ends_on', '2026-07-31')
                ->where('timeline.range.today', '2026-07-21')
                ->where('timeline.range.days', 31)
                ->where('timeline.dates.0.date', '2026-07-01')
                ->where('timeline.dates.30.date', '2026-07-31')
                ->where('timeline.rows.0.id', $this->longNameVehicle->id)
                ->where('timeline.rows.0.name', $this->longNameVehicle->name)
                ->where('timeline.stats.vehicles', 4)
                ->has('options.categories', 2)
                ->has('options.vehicles', 5));
    }

    public function test_timeline_clips_booking_and_maintenance_blocks_at_period_boundaries(): void
    {
        $leftBooking = $this->createBooking(
            $this->longNameVehicle,
            '2026-07-29',
            '2026-08-03',
            BookingStatus::Pending,
        );
        $rightBooking = $this->createBooking(
            $this->car,
            '2026-08-30',
            '2026-09-03',
            BookingStatus::Approved,
        );
        $this->maintenance->create(
            $this->maintenanceVehicle->id,
            '2026-08-15',
            '2026-08-16',
            'Scheduled service',
            BookingActor::admin($this->admin->id),
        );
        $outside = $this->createBooking(
            $this->freeVehicle,
            '2026-09-10',
            '2026-09-11',
            BookingStatus::Pending,
        );

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.occupancies', 3)
                ->where('timeline.rows.0.occupancies.0.booking_public_id', $leftBooking->public_id)
                ->where('timeline.rows.0.occupancies.0.status', 'pending')
                ->where('timeline.rows.0.occupancies.0.start_index', 0)
                ->where('timeline.rows.0.occupancies.0.span_days', 3)
                ->where('timeline.rows.0.occupancies.0.continues_before', true)
                ->where('timeline.rows.1.occupancies.0.booking_public_id', $rightBooking->public_id)
                ->where('timeline.rows.1.occupancies.0.start_index', 29)
                ->where('timeline.rows.1.occupancies.0.span_days', 2)
                ->where('timeline.rows.1.occupancies.0.continues_after', true)
                ->where('timeline.rows.2.occupancies.0.status', 'maintenance')
                ->where('timeline.rows.2.occupancies.0.label', 'Scheduled service')
                ->where('timeline.rows.2.occupancies.0.start_index', 14)
                ->where('timeline.rows.2.occupancies.0.span_days', 2)
                ->has('timeline.rows.3.occupancies', 0));

        $this->actingAs($this->admin)
            ->get(route('bookings.index', ['scope' => 'all', 'per_page' => 50]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.total', 3)
                ->where('bookings.data', function (Collection $rows) use ($leftBooking, $rightBooking, $outside): bool {
                    return $rows->pluck('public_id')->sort()->values()->all() === collect([
                        $leftBooking->public_id,
                        $rightBooking->public_id,
                        $outside->public_id,
                    ])->sort()->values()->all();
                }));
    }

    public function test_timeline_filters_vehicle_inventory_and_occupancy_on_the_server(): void
    {
        $approved = $this->createBooking(
            $this->car,
            '2026-08-10',
            '2026-08-12',
            BookingStatus::Approved,
        );
        $this->maintenance->create(
            $this->maintenanceVehicle->id,
            '2026-08-15',
            '2026-08-16',
            'Oil change',
            BookingActor::admin($this->admin->id),
        );
        $period = ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31'];

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [
                ...$period,
                'vehicle_type' => VehicleType::Car->value,
                'category_id' => $this->carCategory->id,
                'visibility' => 'hidden',
                'status' => BookingStatus::Approved->value,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.vehicles', 1)
                ->where('timeline.rows.0.id', $this->car->id)
                ->where('timeline.rows.0.occupancies.0.booking_public_id', $approved->public_id));

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [...$period, 'status' => 'maintenance']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.vehicles', 1)
                ->where('timeline.rows.0.id', $this->maintenanceVehicle->id)
                ->where('timeline.rows.0.occupancies.0.status', 'maintenance'));

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [...$period, 'available_only' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.vehicles', 2)
                ->where('timeline.stats.available', 2)
                ->has('timeline.rows.0.occupancies', 0)
                ->has('timeline.rows.1.occupancies', 0));

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [...$period, 'visibility' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.vehicles', 1)
                ->where('timeline.rows.0.id', $this->inactiveVehicle->id));

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [...$period, 'vehicle_id' => $this->freeVehicle->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.stats.vehicles', 1)
                ->where('timeline.rows.0.id', $this->freeVehicle->id));
    }

    public function test_timeline_validates_the_custom_period_limit(): void
    {
        $this->actingAs($this->admin)
            ->get(route('timeline.index', [
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-11-01',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.range.days', 93));

        $this->actingAs($this->admin)
            ->get(route('timeline.index', [
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-11-02',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('ends_on');
    }

    public function test_booking_pages_preserve_a_valid_timeline_context_only(): void
    {
        $booking = $this->createBooking(
            $this->longNameVehicle,
            '2026-08-10',
            '2026-08-10',
            BookingStatus::Pending,
        );
        $returnTo = '/timeline?starts_on=2026-08-01&ends_on=2026-08-31&vehicle_type=scooter';

        $this->actingAs($this->admin)
            ->get(route('bookings.show', ['booking' => $booking, 'return_to' => $returnTo]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('return_to', $returnTo));

        $this->actingAs($this->admin)
            ->get(route('bookings.show', ['booking' => $booking, 'return_to' => 'https://example.com']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('return_to', null));

        $this->actingAs($this->admin)
            ->get(route('bookings.show', ['booking' => $booking, 'return_to' => 'javascript:/timeline']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('return_to', null));

        $this->actingAs($this->admin)
            ->get(route('bookings.create', [
                'vehicle_id' => $this->freeVehicle->id,
                'starts_on' => '2026-08-20',
                'ends_on' => '2026-08-20',
                'return_to' => $returnTo,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('defaults.vehicle_id', $this->freeVehicle->id)
                ->where('defaults.starts_on', '2026-08-20')
                ->where('defaults.ends_on', '2026-08-20')
                ->where('return_to', $returnTo));

        $this->actingAs($this->admin)
            ->post(route('bookings.approve', [
                'booking' => $booking,
                'return_to' => $returnTo,
            ]))
            ->assertRedirect(route('bookings.show', [
                'booking' => $booking,
                'return_to' => $returnTo,
            ]));
    }

    public function test_a_slot_that_becomes_occupied_is_rejected_when_the_prefilled_form_is_submitted(): void
    {
        $returnTo = '/timeline?starts_on=2026-08-01&ends_on=2026-08-31';
        $createUrl = route('bookings.create', [
            'vehicle_id' => $this->freeVehicle->id,
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-08-20',
            'return_to' => $returnTo,
        ]);

        $this->actingAs($this->admin)
            ->get($createUrl)
            ->assertInertia(fn (Assert $page) => $page
                ->where('defaults.vehicle_id', $this->freeVehicle->id));

        $competing = $this->createBooking(
            $this->freeVehicle,
            '2026-08-20',
            '2026-08-20',
            BookingStatus::Approved,
        );
        $customerCount = Customer::query()->count();
        $this->assertDatabaseHas('vehicle_occupancies', [
            'vehicle_id' => $this->freeVehicle->id,
            'booking_id' => $competing->id,
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-08-20',
            'blocks_availability' => true,
        ]);
        $this->actingAs($this->admin)
            ->getJson(route('bookings.quote', [
                'vehicle_id' => $this->freeVehicle->id,
                'starts_on' => '2026-08-20',
                'ends_on' => '2026-08-20',
            ]))
            ->assertOk()
            ->assertJsonPath('available', false);

        $this->actingAs($this->admin)
            ->from($createUrl)
            ->post(route('bookings.store', ['return_to' => $returnTo]), $this->manualBookingPayload($this->freeVehicle))
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('booking')
            ->assertSessionHasInput('starts_on', '2026-08-20');

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame($customerCount, Customer::query()->count());
    }

    private function createBooking(
        Vehicle $vehicle,
        string $startsOn,
        string $endsOn,
        BookingStatus $status,
    ): Booking {
        return $this->bookings->create(
            new CreateBookingData(
                customerId: $this->customer->id,
                vehicleId: $vehicle->id,
                startsOn: $startsOn,
                endsOn: $endsOn,
                source: BookingSource::AdminManual,
                initialStatus: $status,
            ),
            BookingActor::admin($this->admin->id),
        );
    }

    private function vehicle(
        string $name,
        VehicleType $type,
        Category $category,
        int $sortOrder,
        bool $visible = false,
        bool $active = true,
    ): Vehicle {
        $vehicle = Vehicle::factory()->create([
            'name' => $name,
            'type' => $type,
            'category_id' => $category->id,
            'sort_order' => $sortOrder,
            'is_active' => $active,
            'is_visible_for_booking' => $visible,
        ]);
        $this->addPrice($vehicle);

        return $vehicle;
    }

    /** @return array<string, mixed> */
    private function manualBookingPayload(Vehicle $vehicle): array
    {
        return [
            'customer_mode' => 'new',
            'customer_id' => null,
            'customer_name' => 'Late Manual Customer',
            'phone' => '+66 80 000 0000',
            'telegram_username' => '',
            'vehicle_id' => $vehicle->id,
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-08-20',
            'pickup_time' => '10:00',
            'return_time' => '10:00',
            'source' => BookingSource::AdminPhone->value,
            'initial_status' => BookingStatus::Approved->value,
            'client_comment' => '',
            'admin_note' => '',
            'deposit_note' => '',
        ];
    }

    private function addPrice(Vehicle $vehicle): void
    {
        $season = PricingSeason::query()->where('key', PricingSeasonKey::Low->value)->firstOrFail();

        VehiclePriceTier::query()->create([
            'vehicle_id' => $vehicle->id,
            'pricing_season_id' => $season->id,
            'tier_key' => PricingTier::OneDay,
            'min_days' => PricingTier::OneDay->minimumDays(),
            'max_days' => PricingTier::OneDay->maximumDays(),
            'anchor_days' => PricingTier::OneDay->anchorDays(),
            'package_total' => 300,
            'daily_rate' => (string) BigDecimal::of(300)->dividedBy(1, 6, RoundingMode::HalfUp),
            'currency' => 'THB',
            'is_active' => true,
        ]);
    }
}
