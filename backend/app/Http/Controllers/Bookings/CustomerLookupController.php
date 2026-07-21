<?php

namespace App\Http\Controllers\Bookings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\SearchCustomersRequest;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CustomerLookupController extends Controller
{
    public function __invoke(SearchCustomersRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));

        $customers = Customer::query()
            ->with('contacts')
            ->where(function (Builder $query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereHas('contacts', fn (Builder $query) => $query
                        ->whereLike('value', "%{$search}%")
                        ->orWhereLike('normalized_value', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
                'contacts' => $customer->contacts->pluck('value')->values()->all(),
            ]);

        return response()->json(['data' => $customers]);
    }
}
