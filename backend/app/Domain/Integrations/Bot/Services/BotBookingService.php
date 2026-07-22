<?php

namespace App\Domain\Integrations\Bot\Services;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Bookings\Services\BookingService;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BotBookingService
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{client_reference: string, booking: Booking}>
     */
    public function createBatch(
        Customer $customer,
        ServiceApiClient $client,
        array $items,
        string $termsVersion,
        ?string $requestId = null,
    ): array {
        $currentTerms = (string) config('business.terms_version');

        if (! hash_equals($currentTerms, $termsVersion)) {
            throw new BookingException('terms_version_outdated', 'The rental terms have changed and must be accepted again.', 409, [
                'current_terms_version' => $currentTerms,
            ]);
        }

        $indexed = [];

        foreach ($items as $index => $item) {
            $indexed[] = ['index' => $index, 'item' => $item];
        }

        usort($indexed, fn (array $left, array $right): int => [
            (int) $left['item']['vehicle_id'],
            (string) $left['item']['client_reference'],
        ] <=> [
            (int) $right['item']['vehicle_id'],
            (string) $right['item']['client_reference'],
        ]);

        return DB::transaction(function () use ($customer, $client, $indexed, $termsVersion, $requestId): array {
            $created = [];
            $acceptedAt = CarbonImmutable::now();

            foreach ($indexed as $entry) {
                $item = $entry['item'];
                $booking = $this->bookings->create(
                    new CreateBookingData(
                        customerId: (int) $customer->id,
                        vehicleId: (int) $item['vehicle_id'],
                        startsOn: (string) $item['starts_on'],
                        endsOn: (string) $item['ends_on'],
                        source: BookingSource::Telegram,
                        initialStatus: BookingStatus::Pending,
                        pickupTime: $item['pickup_time'] ?? null,
                        returnTime: $item['return_time'] ?? null,
                        clientComment: $item['client_comment'] ?? null,
                        helmetsQuantity: (int) ($item['helmets_quantity'] ?? 0),
                        deliveryRequired: (bool) ($item['delivery_required'] ?? false),
                        deliveryAddress: $item['delivery_address'] ?? null,
                        termsAcceptedAt: $acceptedAt,
                        termsVersion: $termsVersion,
                    ),
                    BookingActor::service((int) $client->id),
                    $requestId,
                );
                $created[(int) $entry['index']] = [
                    'client_reference' => (string) $item['client_reference'],
                    'booking' => $booking,
                ];
            }

            ksort($created);

            return array_values($created);
        }, 3);
    }
}
