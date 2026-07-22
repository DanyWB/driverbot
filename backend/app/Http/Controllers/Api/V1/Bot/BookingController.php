<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Domain\Integrations\Bot\Services\BotBookingService;
use App\Domain\Integrations\Bot\Services\IdempotentBotAction;
use App\Http\Controllers\Api\V1\Bot\Concerns\HandlesBotApiContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\CancelBotBookingRequest;
use App\Http\Requests\Api\V1\Bot\ListBotBookingsRequest;
use App\Http\Requests\Api\V1\Bot\StoreBotBookingsRequest;
use App\Http\Responses\BotApiResponse;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use HandlesBotApiContext;

    public function store(
        StoreBotBookingsRequest $request,
        BotBookingService $bookings,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $data = $request->validated();
        $customer = $this->customer($request);

        return $idempotent->execute($request, "customers.{$customer->id}.bookings.create", $data, function () use ($request, $bookings, $presenter, $data, $customer): array {
            $created = $bookings->createBatch(
                $customer,
                $this->serviceClient($request),
                $data['items'],
                (string) $data['terms_version'],
                $this->requestId($request),
            );

            return [
                'status' => 201,
                'data' => array_map(function (array $entry) use ($presenter): array {
                    $booking = $this->loadBooking($entry['booking']);

                    return [
                        'client_reference' => $entry['client_reference'],
                        'booking' => $presenter->booking($booking),
                    ];
                }, $created),
            ];
        });
    }

    public function index(
        ListBotBookingsRequest $request,
        BotApiPresenter $presenter,
    ): JsonResponse {
        $data = $request->validated();
        $scope = $data['scope'] ?? 'current';
        $current = [BookingStatus::Process, BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active];
        $query = Booking::query()
            ->where('customer_id', $this->customer($request)->id)
            ->with($this->relations())
            ->orderByDesc('starts_on')
            ->orderByDesc('id');

        if ($scope === 'current') {
            $query->whereIn('status', array_map(fn (BookingStatus $status): string => $status->value, $current));
        } elseif ($scope === 'history') {
            $query->whereNotIn('status', array_map(fn (BookingStatus $status): string => $status->value, $current));
        }

        $items = $query
            ->offset((int) ($data['offset'] ?? 0))
            ->limit((int) ($data['limit'] ?? 50))
            ->get()
            ->map(fn (Booking $booking): array => $presenter->booking($booking))
            ->values()
            ->all();

        return BotApiResponse::success($request, $items);
    }

    public function show(Request $request, string $booking, BotApiPresenter $presenter): JsonResponse
    {
        $model = $this->ownedBooking($request, $booking);

        return BotApiResponse::success($request, $presenter->booking($this->loadBooking($model)));
    }

    public function cancel(
        CancelBotBookingRequest $request,
        string $booking,
        BookingService $bookings,
        BotApiPresenter $presenter,
        IdempotentBotAction $idempotent,
    ): JsonResponse {
        $model = $this->ownedBooking($request, $booking);
        $data = $request->validated();

        return $idempotent->execute($request, "bookings.{$model->public_id}.cancel", $data, function () use ($request, $bookings, $presenter, $model, $data): array {
            $cancelled = $bookings->cancelByClient(
                $model,
                BookingActor::customer((int) $this->customer($request)->id),
                $data['reason'] ?? null,
                requestId: $this->requestId($request),
            );

            return ['status' => 200, 'data' => $presenter->booking($this->loadBooking($cancelled))];
        });
    }

    private function ownedBooking(Request $request, string $publicId): Booking
    {
        $booking = Booking::query()
            ->where('public_id', $publicId)
            ->where('customer_id', $this->customer($request)->id)
            ->first();

        if (! $booking instanceof Booking) {
            throw new BookingException('booking_not_found', 'Booking was not found.', 404);
        }

        return $booking;
    }

    private function loadBooking(Booking $booking): Booking
    {
        return $booking->load($this->relations());
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['vehicle.category', 'vehicle.photos', 'latestPriceSnapshot', 'documents.booking'];
    }
}
