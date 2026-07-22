<?php

namespace App\Domain\Bookings\Presenters;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Customers\Enums\ContactType;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\BookingStatusHistory;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\ServiceApiClient;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class AdminBookingPresenter
{
    /** @return array<string, mixed> */
    public function listItem(Booking $booking): array
    {
        $snapshot = $booking->latestPriceSnapshot;

        return [
            'public_id' => (string) $booking->public_id,
            'status' => $booking->bookingStatus()->value,
            'source' => (string) $booking->getRawOriginal('source'),
            'customer' => [
                'id' => (int) $booking->customer->id,
                'name' => (string) $booking->customer->name,
                'phone' => $this->contact($booking, ContactType::Phone),
                'telegram' => $this->contact($booking, ContactType::TelegramUsername),
            ],
            'vehicle' => [
                'id' => (int) $booking->vehicle->id,
                'name' => (string) $booking->vehicle->name,
                'type' => (string) $booking->vehicle->getRawOriginal('type'),
                'inventory_code' => $this->nullableString($booking->vehicle->inventory_code),
            ],
            'starts_on' => $this->date($booking, 'starts_on'),
            'ends_on' => $this->date($booking, 'ends_on'),
            'pickup_time' => $this->time($booking, 'pickup_time'),
            'total_days' => $this->totalDays($booking),
            'price' => $snapshot instanceof BookingPriceSnapshot ? $this->priceSummary($snapshot) : null,
            'documents_count' => (int) $booking->documents_count,
            'created_at' => $this->dateTime($booking->created_at),
            'updated_at' => $this->dateTime($booking->updated_at),
        ];
    }

    /** @return list<string> */
    public function csvHeaders(): array
    {
        return [
            'booking_id',
            'status',
            'source',
            'client_name',
            'phone',
            'telegram_username',
            'vehicle_name',
            'vehicle_type',
            'start_date',
            'end_date',
            'start_time',
            'days',
            'calculated_total',
            'final_total',
            'currency',
            'payment_note',
            'helmets_quantity',
            'delivery_required',
            'delivery_address',
            'terms_version',
            'terms_accepted_at',
            'documents_status',
            'client_comment',
            'admin_note',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<int|string|null> */
    public function csvRow(Booking $booking): array
    {
        $snapshot = $booking->latestPriceSnapshot;

        return [
            (string) $booking->public_id,
            $booking->bookingStatus()->value,
            (string) $booking->getRawOriginal('source'),
            (string) $booking->customer->name,
            $this->contact($booking, ContactType::Phone),
            $this->contact($booking, ContactType::TelegramUsername),
            (string) $booking->vehicle->name,
            (string) $booking->vehicle->getRawOriginal('type'),
            $this->date($booking, 'starts_on'),
            $this->date($booking, 'ends_on'),
            $this->time($booking, 'pickup_time'),
            $this->totalDays($booking),
            $snapshot instanceof BookingPriceSnapshot ? (string) $snapshot->calculated_total : null,
            $snapshot instanceof BookingPriceSnapshot ? (int) $snapshot->final_total : null,
            $snapshot instanceof BookingPriceSnapshot ? (string) $snapshot->currency : null,
            $this->nullableString($booking->deposit_note),
            (int) $booking->helmets_quantity,
            (bool) $booking->delivery_required ? 'yes' : 'no',
            $this->nullableString($booking->delivery_address),
            $this->nullableString($booking->terms_version),
            $this->dateTime($booking->terms_accepted_at),
            (int) $booking->documents_count > 0 ? 'yes' : 'no',
            $this->nullableString($booking->client_comment),
            $this->nullableString($booking->admin_note),
            $this->dateTime($booking->created_at),
            $this->dateTime($booking->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Booking $booking): array
    {
        return [
            ...$this->listItem($booking),
            'return_time' => $this->time($booking, 'return_time'),
            'client_comment' => $this->nullableString($booking->client_comment),
            'admin_note' => $this->nullableString($booking->admin_note),
            'cancellation_reason' => $this->nullableString($booking->cancellation_reason),
            'no_show_reason' => $this->nullableString($booking->no_show_reason),
            'deposit_note' => $this->nullableString($booking->deposit_note),
            'options' => [
                'helmets_quantity' => (int) $booking->helmets_quantity,
                'delivery_required' => (bool) $booking->delivery_required,
                'delivery_address' => $this->nullableString($booking->delivery_address),
            ],
            'terms' => $booking->terms_accepted_at === null ? null : [
                'version' => $this->nullableString($booking->terms_version),
                'accepted_at' => $this->dateTime($booking->terms_accepted_at),
            ],
            'pending_expires_at' => $this->dateTime($booking->pending_expires_at),
            'created_by_admin' => $booking->createdByAdmin === null ? null : [
                'id' => (int) $booking->createdByAdmin->id,
                'name' => (string) $booking->createdByAdmin->name,
            ],
            'price_snapshots' => $booking->priceSnapshots
                ->map(fn (BookingPriceSnapshot $snapshot): array => [
                    ...$this->priceSummary($snapshot),
                    'version' => (int) $snapshot->version,
                    'total_days' => (int) $snapshot->total_days,
                    'tier_key' => (string) $snapshot->getRawOriginal('tier_key'),
                    'calculated_total' => (string) $snapshot->calculated_total,
                    'rounded_total' => (int) $snapshot->rounded_total,
                    'manual_total' => is_numeric($snapshot->manual_total) ? (int) $snapshot->manual_total : null,
                    'pricing_source' => $snapshot->pricingSource()->value,
                    'override_reason' => $this->nullableString($snapshot->override_reason),
                    'breakdown' => $snapshot->breakdownItems(),
                    'calculated_at' => $this->dateTime($snapshot->calculated_at),
                    'overridden_by_admin' => $snapshot->overriddenByAdmin === null ? null : [
                        'id' => (int) $snapshot->overriddenByAdmin->id,
                        'name' => (string) $snapshot->overriddenByAdmin->name,
                    ],
                ])
                ->values()
                ->all(),
            'status_history' => $booking->statusHistory
                ->sortByDesc('created_at')
                ->map(fn (BookingStatusHistory $history): array => [
                    'id' => (int) $history->id,
                    'from_status' => $this->nullableString($history->getRawOriginal('from_status')),
                    'to_status' => (string) $history->getRawOriginal('to_status'),
                    'actor_type' => (string) $history->getRawOriginal('actor_type'),
                    'actor_name' => $this->actorName($history),
                    'reason' => $this->nullableString($history->reason),
                    'context' => $history->contextValues(),
                    'created_at' => $this->dateTime($history->created_at),
                ])
                ->values()
                ->all(),
            'documents' => $booking->documents->map(fn ($document): array => [
                'id' => (int) $document->id,
                'type' => (string) $document->type,
                'filename' => (string) $document->original_filename,
                'download_url' => route('documents.download', $document),
                'created_at' => $this->dateTime($document->created_at),
            ])->values()->all(),
            'actions' => $this->actions($booking),
        ];
    }

    /** @return array<string, bool> */
    private function actions(Booking $booking): array
    {
        $status = $booking->bookingStatus();

        return [
            'approve' => $status === BookingStatus::Pending,
            'activate' => $status === BookingStatus::Approved,
            'complete' => $status === BookingStatus::Active,
            'cancel' => in_array($status, [BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active], true),
            'no_show' => $status === BookingStatus::Approved && ! now($this->timezone())->isBefore($this->startsAt($booking)),
            'change_dates' => in_array($status, [BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active], true),
            'override_price' => in_array($status, [BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active], true),
        ];
    }

    private function contact(Booking $booking, ContactType $type): ?string
    {
        $contact = $booking->customer->contacts
            ->first(fn (CustomerContact $contact): bool => $contact->getRawOriginal('type') === $type->value);

        return $contact instanceof CustomerContact ? (string) $contact->value : null;
    }

    /** @return array{final_total: int, calculated_total: string, currency: string, pricing_source: string} */
    private function priceSummary(BookingPriceSnapshot $snapshot): array
    {
        return [
            'final_total' => (int) $snapshot->final_total,
            'calculated_total' => (string) $snapshot->calculated_total,
            'currency' => (string) $snapshot->currency,
            'pricing_source' => $snapshot->pricingSource()->value,
        ];
    }

    private function totalDays(Booking $booking): int
    {
        $start = CarbonImmutable::parse($this->date($booking, 'starts_on'), $this->timezone());
        $end = CarbonImmutable::parse($this->date($booking, 'ends_on'), $this->timezone());

        return (int) $start->diffInDays($end) + 1;
    }

    private function actorName(BookingStatusHistory $history): ?string
    {
        $admin = $history->getRelation('actorAdmin');

        if ($admin instanceof User) {
            return (string) $admin->name;
        }

        $customer = $history->getRelation('actorCustomer');

        if ($customer instanceof Customer) {
            return (string) $customer->name;
        }

        $serviceClient = $history->getRelation('actorServiceClient');

        return $serviceClient instanceof ServiceApiClient ? (string) $serviceClient->name : null;
    }

    private function startsAt(Booking $booking): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->date($booking, 'starts_on').' '.($this->time($booking, 'pickup_time') ?? '00:00:00'),
            $this->timezone(),
        );
    }

    private function date(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $booking->getRawOriginal($attribute), 0, 10);
    }

    private function time(Booking $booking, string $attribute): ?string
    {
        $value = $this->nullableString($booking->getRawOriginal($attribute));

        return $value === null ? null : substr($value, 0, 5);
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone($this->timezone())->toIso8601String()
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function timezone(): string
    {
        return (string) config('business.timezone', 'Asia/Bangkok');
    }
}
