<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\BookingReasonRequest;
use App\Http\Requests\Bookings\MarkBookingNoShowRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingStatusController extends Controller
{
    use HandlesBookingFailures;

    public function approve(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->transition($request, $booking, 'Booking approved.', fn (BookingActor $actor) => $bookings->approve(
            $booking,
            $actor,
            $this->requestId($request),
        ));
    }

    public function activate(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->transition($request, $booking, 'Rental started.', fn (BookingActor $actor) => $bookings->activate(
            $booking,
            $actor,
            $this->requestId($request),
        ));
    }

    public function complete(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->transition($request, $booking, 'Rental completed.', fn (BookingActor $actor) => $bookings->complete(
            $booking,
            $actor,
            $this->requestId($request),
        ));
    }

    public function cancel(BookingReasonRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->transition($request, $booking, 'Booking cancelled.', fn (BookingActor $actor) => $bookings->cancelByAdmin(
            $booking,
            $actor,
            (string) $request->validated('reason'),
            $this->requestId($request),
        ));
    }

    public function noShow(MarkBookingNoShowRequest $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->transition($request, $booking, 'Booking marked as no-show.', fn (BookingActor $actor) => $bookings->markNoShow(
            $booking,
            $actor,
            $request->string('reason')->trim()->toString() ?: null,
            requestId: $this->requestId($request),
        ));
    }

    /** @param callable(BookingActor): Booking $operation */
    private function transition(Request $request, Booking $booking, string $message, callable $operation): RedirectResponse
    {
        try {
            $operation(BookingActor::admin($this->adminFrom($request)->id));
        } catch (BookingException $exception) {
            $this->throwAsValidation($exception, 'action');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return $this->bookingActionRedirect($request, $booking);
    }
}
