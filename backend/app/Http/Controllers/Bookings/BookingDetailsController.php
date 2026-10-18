<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Services\BookingDetailsService;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\UpdateBookingDetailsRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingDetailsController extends Controller
{
    use HandlesBookingFailures;

    public function update(UpdateBookingDetailsRequest $request, Booking $booking, BookingDetailsService $service): RedirectResponse
    {
        $service->update($booking, $request->validated(), $this->adminFrom($request), $this->requestId($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking details saved.']);

        return $this->bookingShowRedirect($request, $booking);
    }
}
