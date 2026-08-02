<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Presenters\AdminBookingPresenter;
use App\Domain\Bookings\Queries\AdminBookingQuery;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Customers\Services\ManualCustomerService;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\ListBookingsRequest;
use App\Http\Requests\Bookings\StoreManualBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    use HandlesBookingFailures;

    public function index(
        ListBookingsRequest $request,
        AdminBookingQuery $query,
        AdminBookingPresenter $presenter,
    ): Response {
        $filters = $request->filters();
        $bookings = $query->paginate($filters)->through(
            fn (Booking $booking): array => $presenter->listItem($booking),
        );
        $today = now((string) config('business.timezone'))->toDateString();

        return Inertia::render('bookings/Index', [
            'bookings' => $bookings,
            'filters' => $filters,
            'summary' => [
                'pending' => Booking::query()->where('status', BookingStatus::Pending->value)->count(),
                'approved' => Booking::query()->where('status', BookingStatus::Approved->value)->count(),
                'active' => Booking::query()->where('status', BookingStatus::Active->value)->count(),
                'pickups_today' => Booking::query()
                    ->whereIn('status', [BookingStatus::Approved->value, BookingStatus::Active->value])
                    ->whereDate('starts_on', $today)
                    ->count(),
            ],
            'options' => [
                'statuses' => array_column(BookingStatus::cases(), 'value'),
                'sources' => array_column(BookingSource::cases(), 'value'),
                'vehicle_types' => array_column(VehicleType::cases(), 'value'),
                'vehicles' => Vehicle::query()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'type'])
                    ->map(fn (Vehicle $vehicle): array => [
                        'id' => (int) $vehicle->id,
                        'name' => (string) $vehicle->name,
                        'type' => (string) $vehicle->getRawOriginal('type'),
                    ]),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $defaultCustomer = Customer::query()
            ->with('contacts')
            ->find($request->integer('customer_id'));

        return Inertia::render('bookings/Create', [
            'vehicles' => Vehicle::query()
                ->where('is_active', true)
                ->withCount(['priceTiers as active_price_tiers_count' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Vehicle $vehicle): array => [
                    'id' => (int) $vehicle->id,
                    'name' => (string) $vehicle->name,
                    'type' => (string) $vehicle->getRawOriginal('type'),
                    'inventory_code' => $vehicle->inventory_code,
                    'is_visible' => (bool) $vehicle->is_visible_for_booking,
                    'has_complete_pricing' => (int) $vehicle->getAttribute('active_price_tiers_count') === 15,
                ]),
            'defaults' => [
                'starts_on' => $request->string('starts_on')->toString(),
                'ends_on' => $request->string('ends_on')->toString(),
                'vehicle_id' => $request->integer('vehicle_id') ?: null,
            ],
            'default_customer' => $defaultCustomer instanceof Customer ? [
                'id' => (int) $defaultCustomer->id,
                'name' => (string) $defaultCustomer->name,
                'contacts' => $defaultCustomer->contacts->pluck('value')->map(fn ($value): string => (string) $value)->values()->all(),
            ] : null,
            'return_to' => $this->timelineReturnTo($request),
        ]);
    }

    public function store(
        StoreManualBookingRequest $request,
        ManualCustomerService $customers,
        BookingService $bookings,
        BookingPriceSnapshotService $snapshots,
    ): RedirectResponse {
        $data = $request->validated();
        $admin = $this->adminFrom($request);

        try {
            $booking = DB::transaction(function () use ($data, $admin, $customers, $bookings, $snapshots, $request): Booking {
                $customer = $customers->resolve(
                    $data['customer_mode'] === 'existing' ? (int) $data['customer_id'] : null,
                    $this->nullable($data['customer_name'] ?? null),
                    $this->nullable($data['phone'] ?? null),
                    $this->nullable($data['telegram_username'] ?? null),
                );
                $booking = $bookings->create(
                    new CreateBookingData(
                        customerId: $customer->id,
                        vehicleId: (int) $data['vehicle_id'],
                        startsOn: (string) $data['starts_on'],
                        endsOn: (string) $data['ends_on'],
                        source: BookingSource::from((string) $data['source']),
                        initialStatus: BookingStatus::from((string) $data['initial_status']),
                        pickupTime: $this->nullable($data['pickup_time'] ?? null),
                        returnTime: $this->nullable($data['return_time'] ?? null),
                        clientComment: $this->nullable($data['client_comment'] ?? null),
                        adminNote: $this->nullable($data['admin_note'] ?? null),
                        depositNote: $this->nullable($data['deposit_note'] ?? null),
                    ),
                    BookingActor::admin($admin->id),
                    $this->requestId($request),
                );

                if (isset($data['manual_total'])) {
                    $snapshots->override(
                        $booking,
                        (int) $data['manual_total'],
                        (string) $data['override_reason'],
                        $admin,
                        $this->requestId($request),
                    );
                }

                return $booking;
            });
        } catch (BookingException|PricingException $exception) {
            $this->throwAsValidation($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking created.']);

        $timelineReturnTo = $this->timelineReturnTo($request);

        if ($request->boolean('return_to_timeline') && $timelineReturnTo !== null) {
            return redirect()->to($timelineReturnTo);
        }

        return $this->bookingShowRedirect($request, $booking);
    }

    public function show(Request $request, Booking $booking, AdminBookingPresenter $presenter): Response
    {
        $booking->load([
            'customer.contacts',
            'vehicle',
            'priceSnapshots.overriddenByAdmin',
            'latestPriceSnapshot',
            'statusHistory.actorAdmin',
            'statusHistory.actorCustomer',
            'statusHistory.actorServiceClient',
            'documents',
            'createdByAdmin',
        ])->loadCount('documents');

        return Inertia::render('bookings/Show', [
            'booking' => $presenter->detail($booking),
            'return_to' => $this->timelineReturnTo($request),
        ]);
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
