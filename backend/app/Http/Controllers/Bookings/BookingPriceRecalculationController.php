<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class BookingPriceRecalculationController extends Controller
{
    use HandlesBookingFailures;

    public function store(
        Request $request,
        Booking $booking,
        BookingPriceSnapshotService $snapshots,
    ): RedirectResponse {
        try {
            $snapshots->recalculate(
                $booking,
                $this->adminFrom($request),
                $this->requestId($request),
            );
        } catch (PricingException $exception) {
            $this->throwAsValidation($exception, 'price');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'price' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price recalculated from current tariffs.']);

        return $this->bookingShowRedirect($request, $booking);
    }
}
