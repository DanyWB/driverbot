<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Availability\Services\BookingAvailabilityService;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bookings\BookingQuoteRequest;
use App\Models\Booking;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class BookingQuoteController extends Controller
{
    public function create(
        BookingQuoteRequest $request,
        PricingService $pricing,
        BookingAvailabilityService $availability,
    ): JsonResponse {
        $vehicle = Vehicle::query()->findOrFail((int) $request->validated('vehicle_id'));

        return $this->quote($request, $vehicle, $pricing, $availability);
    }

    public function booking(
        BookingQuoteRequest $request,
        Booking $booking,
        PricingService $pricing,
        BookingAvailabilityService $availability,
    ): JsonResponse {
        return $this->quote($request, $booking->vehicle()->firstOrFail(), $pricing, $availability, $booking->id);
    }

    private function quote(
        BookingQuoteRequest $request,
        Vehicle $vehicle,
        PricingService $pricing,
        BookingAvailabilityService $availability,
        ?int $excludeBookingId = null,
    ): JsonResponse {
        $startsOn = (string) $request->validated('starts_on');
        $endsOn = (string) $request->validated('ends_on');

        try {
            $quote = $pricing->quote(
                $vehicle,
                RentalPeriod::fromStrings($startsOn, $endsOn, (string) config('business.timezone')),
                requireBookable: false,
            );

            return response()->json([
                'quote' => $quote->toArray(),
                'available' => $availability->isAvailable($vehicle, $startsOn, $endsOn, $excludeBookingId),
            ]);
        } catch (BookingException|PricingException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception instanceof BookingException ? $exception->httpStatus : 422);
        }
    }
}
