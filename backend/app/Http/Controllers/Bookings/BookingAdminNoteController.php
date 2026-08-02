<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Http\Controllers\Bookings\Concerns\HandlesBookingFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\UpdateBookingAdminNoteRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingAdminNoteController extends Controller
{
    use HandlesBookingFailures;

    public function update(
        UpdateBookingAdminNoteRequest $request,
        Booking $booking,
        BookingService $bookings,
    ): RedirectResponse {
        try {
            $bookings->updateAdminNote(
                $booking,
                $this->nullable($request->validated('admin_note')),
                BookingActor::admin($this->adminFrom($request)->id),
                $this->requestId($request),
            );
        } catch (BookingException $exception) {
            $this->throwAsValidation($exception, 'admin_note');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Internal note updated.']);

        return $this->bookingShowRedirect($request, $booking);
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
