<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Availability\Services\BookingAvailabilityService;
use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\BotQuoteRequest;
use App\Http\Responses\BotApiResponse;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    public function __invoke(
        BotQuoteRequest $request,
        PricingService $pricing,
        BookingAvailabilityService $availability,
        BotApiPresenter $presenter,
    ): JsonResponse {
        $data = $request->validated();
        $vehicle = Vehicle::query()
            ->where('is_active', true)
            ->where('is_visible_for_booking', true)
            ->find($data['vehicle_id']);

        if (! $vehicle instanceof Vehicle) {
            throw new BotApiException('vehicle_not_found', 'Vehicle was not found.', 404);
        }

        $quote = $pricing->quote(
            $vehicle,
            RentalPeriod::fromStrings($data['starts_on'], $data['ends_on']),
            true,
        );

        return BotApiResponse::success($request, $presenter->quote(
            $quote,
            $availability->isAvailable($vehicle, $data['starts_on'], $data['ends_on']),
        ));
    }
}
