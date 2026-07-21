<?php

namespace App\Http\Controllers\Bookings\Concerns;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait HandlesBookingFailures
{
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
