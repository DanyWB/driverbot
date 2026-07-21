<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\OverrideBookingPriceRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class BookingPriceOverrideController extends Controller
{
    use HandlesBookingFailures;

    public function store(
        OverrideBookingPriceRequest $request,
        Booking $booking,
        BookingPriceSnapshotService $snapshots,
    ): RedirectResponse {
        try {
            $snapshots->override(
                $booking,
                (int) $request->validated('manual_total'),
                (string) $request->validated('reason'),
                $this->adminFrom($request),
                $this->requestId($request),
            );
        } catch (PricingException $exception) {
            $this->throwAsValidation($exception, 'price');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['price' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Final price updated.']);

        return $this->bookingShowRedirect($request, $booking);
    }
}
