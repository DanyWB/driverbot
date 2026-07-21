<?php

namespace App\Http\Controllers\Customers;

use App\Domain\Bookings\Presenters\AdminBookingPresenter;
use App\Domain\Customers\Presenters\AdminCustomerPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ListCustomersRequest;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(
        ListCustomersRequest $request,
        AdminCustomerPresenter $presenter,
    ): Response {
        $filters = $request->filters();
        $query = Customer::query()
            ->with(['contacts', 'latestBooking.vehicle'])
            ->withCount(['bookings', 'documents']);
        $search = trim((string) $filters['search']);

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereHas('contacts', fn (Builder $query) => $query
                    ->whereLike('value', "%{$search}%")
                    ->orWhereLike('normalized_value', "%{$search}%"))
                ->orWhereHas('identities', fn (Builder $query) => $query
                    ->whereLike('external_id', "%{$search}%")));
        }

        if ($filters['documents'] === 'yes') {
            $query->has('documents');
        } elseif ($filters['documents'] === 'no') {
            $query->doesntHave('documents');
        }

        $direction = $filters['direction'] === 'asc' ? 'asc' : 'desc';
        $customers = $query
            ->orderBy((string) $filters['sort'], $direction)
            ->orderByDesc('id')
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(fn (Customer $customer): array => $presenter->listItem($customer));

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
            'summary' => [
                'total' => Customer::query()->count(),
                'with_bookings' => Customer::query()->has('bookings')->count(),
                'with_documents' => Customer::query()->has('documents')->count(),
            ],
        ]);
    }

    public function show(
        Customer $customer,
        AdminCustomerPresenter $customers,
        AdminBookingPresenter $bookings,
    ): Response {
        $customer->load(['contacts', 'identities', 'documents.booking', 'latestBooking.vehicle'])
            ->loadCount(['bookings', 'documents']);
        $bookingHistory = $customer->bookings()
            ->with(['customer.contacts', 'vehicle', 'latestPriceSnapshot'])
            ->withCount('documents')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Booking $booking): array => $bookings->listItem($booking));

        return Inertia::render('customers/Show', [
            'customer' => $customers->detail($customer),
            'bookings' => $bookingHistory,
        ]);
    }
}
