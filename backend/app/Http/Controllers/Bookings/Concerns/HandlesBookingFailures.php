<?php

namespace App\Http\Controllers\Bookings\Concerns;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait HandlesBookingFailures
{
    private function bookingShowRedirect(Request $request, Booking $booking): RedirectResponse
    {
        $parameters = ['booking' => $booking];
        $returnTo = $this->timelineReturnTo($request);

        if ($returnTo !== null) {
            $parameters['return_to'] = $returnTo;
        }

        return to_route('bookings.show', $parameters);
    }

    private function bookingActionRedirect(Request $request, Booking $booking): RedirectResponse
    {
        $returnTo = $this->safeReturnTo($request);

        if ($returnTo !== null && parse_url($returnTo, PHP_URL_PATH) === '/bookings') {
            return redirect()->to($returnTo);
        }

        return $this->bookingShowRedirect($request, $booking);
    }

    private function timelineReturnTo(Request $request): ?string
    {
        $returnTo = $this->safeReturnTo($request);

        return $returnTo !== null && parse_url($returnTo, PHP_URL_PATH) === '/timeline'
            ? $returnTo
            : null;
    }

    private function safeReturnTo(Request $request): ?string
    {
        $returnTo = $request->query('return_to');

        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > 2000) {
            return null;
        }

        $parts = parse_url($returnTo);

        if ($parts === false
            || ! in_array(($parts['path'] ?? null), ['/timeline', '/bookings'], true)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        return $returnTo;
    }

    private function adminFrom(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) ? $requestId : null;
    }

    private function throwAsValidation(BookingException|PricingException $exception, string $field = 'booking'): never
    {
        $message = match ($exception->errorCode) {
            'vehicle_unavailable' => 'This vehicle is no longer available for the selected dates. Your form data was kept.',
            'price_missing' => 'The selected vehicle has no complete price for this rental period.',
            'vehicle_hidden' => 'The selected vehicle is not available for booking.',
            'invalid_booking_transition' => 'The booking changed and this action is no longer available. Refresh and try again.',
            'no_show_too_early' => 'No-show can only be recorded after the planned pickup time.',
            default => $exception->getMessage(),
        };

        throw ValidationException::withMessages([$field => $message]);
    }
}
