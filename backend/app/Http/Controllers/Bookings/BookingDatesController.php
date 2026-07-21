<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\ChangeBookingDatesData;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\UpdateBookingDatesRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingDatesController extends Controller
{
    use HandlesBookingFailures;

    public function update(UpdateBookingDatesRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        $data = $request->validated();

        try {
            $bookings->changeDates(
                $booking,
                new ChangeBookingDatesData(
                    startsOn: (string) $data['starts_on'],
                    endsOn: (string) $data['ends_on'],
                    pickupTime: $this->nullable($data['pickup_time'] ?? null),
                    returnTime: $this->nullable($data['return_time'] ?? null),
                ),
                BookingActor::admin($this->adminFrom($request)->id),
                $this->requestId($request),
            );
        } catch (BookingException|PricingException $exception) {
            $this->throwAsValidation($exception, 'dates');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Booking dates and price updated.']);

        return $this->bookingShowRedirect($request, $booking);
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
