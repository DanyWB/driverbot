<?php

namespace App\Domain\Integrations\Bot\Presenters;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Customers\Enums\ContactType;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Vehicles\Services\VehiclePhotoService;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerDocument;
use App\Models\CustomerIdentity;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class BotApiPresenter
{
    public function __construct(private readonly VehiclePhotoService $photos) {}

    /** @return array<string, mixed> */
    public function category(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'code' => (string) $category->code,
            'name' => (string) $category->name,
            'vehicle_type' => $category->getRawOriginal('vehicle_type'),
            'description' => $category->description,
            'vehicles_count' => (int) ($category->getAttribute('vehicles_count') ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function vehicle(Vehicle $vehicle): array
    {
        $primary = $vehicle->photos->firstWhere('is_primary', true) ?? $vehicle->photos->first();

        return [
            'id' => (int) $vehicle->id,
            'type' => (string) $vehicle->getRawOriginal('type'),
            'name' => (string) $vehicle->name,
            'year' => is_numeric($vehicle->year) ? (int) $vehicle->year : null,
            'description' => $vehicle->description,
            'characteristics' => $vehicle->characteristics_text,
            'emoji' => $vehicle->emoji,
            'category' => $vehicle->category instanceof Category ? $this->category($vehicle->category) : null,
            'primary_photo' => $primary instanceof VehiclePhoto ? $this->photo($primary) : null,
            'photos' => $vehicle->photos->map(fn (VehiclePhoto $photo): array => $this->photo($photo))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function customer(Customer $customer): array
    {
        $private = $customer->privateData();
        $identity = $customer->identities->first(fn (CustomerIdentity $identity): bool => $identity->getRawOriginal('provider') === IdentityProvider::Telegram->value);
        $nameCompleted = (bool) ($private['name_completed'] ?? (trim((string) $customer->name) !== ''));
        $languageCompleted = (bool) ($private['language_completed'] ?? false);
        $passportNumber = isset($private['passport_number']) ? (string) $private['passport_number'] : null;
        $hasPassportDocument = $customer->documents->contains(fn (CustomerDocument $document): bool => $document->type === 'passport');

        return [
            'id' => (int) $customer->id,
            'name' => $nameCompleted ? (string) $customer->name : null,
            'locale' => $languageCompleted ? (string) $customer->locale : null,
            'phone' => $this->contact($customer, ContactType::Phone),
            'telegram' => [
                'id' => $identity?->external_id,
                'username' => $this->contact($customer, ContactType::TelegramUsername),
            ],
            'passport' => [
                'number' => $passportNumber,
                'has_document' => $hasPassportDocument,
            ],
            'profile_complete' => [
                'name' => $nameCompleted,
                'language' => $languageCompleted,
                'phone' => $this->contact($customer, ContactType::Phone) !== null,
                'passport' => $passportNumber !== null || $hasPassportDocument,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function quote(PriceQuote $quote, bool $available): array
    {
        return [
            ...$quote->toArray(),
            'average_daily_rate' => (int) round($quote->finalTotal / max(1, $quote->totalDays)),
            'available' => $available,
        ];
    }

    /** @return array<string, mixed> */
    public function booking(Booking $booking): array
    {
        $snapshot = $booking->latestPriceSnapshot;
        $cancellation = $this->cancellation($booking);

        return [
            'public_id' => (string) $booking->public_id,
            'status' => $booking->bookingStatus()->value,
            'vehicle' => $this->vehicle($booking->vehicle),
            'starts_on' => $this->date($booking->getAttribute('starts_on')),
            'ends_on' => $this->date($booking->getAttribute('ends_on')),
            'pickup_time' => $this->time($booking->getRawOriginal('pickup_time')),
            'return_time' => $this->time($booking->getRawOriginal('return_time')),
            'price' => $snapshot instanceof BookingPriceSnapshot ? [
                'total_days' => (int) $snapshot->total_days,
                'tier_key' => (string) $snapshot->getRawOriginal('tier_key'),
                'calculated_total' => (string) $snapshot->calculated_total,
                'final_total' => (int) $snapshot->final_total,
                'currency' => (string) $snapshot->currency,
            ] : null,
            'options' => [
                'helmets_quantity' => (int) $booking->helmets_quantity,
                'delivery_required' => (bool) $booking->delivery_required,
                'delivery_address' => $booking->delivery_address,
                'client_comment' => $booking->client_comment,
            ],
            'terms' => [
                'version' => $booking->terms_version,
                'accepted_at' => $this->dateTime($booking->getAttribute('terms_accepted_at')),
            ],
            'documents' => $booking->documents->map(fn (CustomerDocument $document): array => $this->document($document))->values()->all(),
            'can_cancel' => $cancellation['can_cancel'],
            'cancellation' => $cancellation,
            'pending_expires_at' => $this->dateTime($booking->getAttribute('pending_expires_at')),
            'created_at' => $this->dateTime($booking->created_at),
            'updated_at' => $this->dateTime($booking->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public function document(CustomerDocument $document): array
    {
        return [
            'id' => (int) $document->id,
            'type' => (string) $document->type,
            'filename' => (string) $document->original_filename,
            'mime_type' => $document->mime_type,
            'file_size' => is_numeric($document->file_size) ? (int) $document->file_size : null,
            'booking_public_id' => $document->booking?->public_id,
            'created_at' => $this->dateTime($document->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function photo(VehiclePhoto $photo): array
    {
        return [
            'id' => (int) $photo->id,
            'url' => $this->photos->url($photo),
            'thumbnail_url' => $this->photos->thumbnailUrl($photo),
            'alt_text' => $photo->alt_text,
            'is_primary' => (bool) $photo->is_primary,
        ];
    }

    private function contact(Customer $customer, ContactType $type): ?string
    {
        $contact = $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->getRawOriginal('type') === $type->value && $contact->is_primary)
            ?? $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->getRawOriginal('type') === $type->value);

        return $contact instanceof CustomerContact ? (string) $contact->value : null;
    }

    /** @return array{can_cancel: bool, requires_manager: bool, manager_telegram: mixed, reason: string|null} */
    private function cancellation(Booking $booking): array
    {
        $status = $booking->bookingStatus();
        $requiresManager = false;

        if ($status === BookingStatus::Approved) {
            $time = (string) ($booking->getRawOriginal('pickup_time') ?: '00:00:00');
            $startsAt = CarbonImmutable::parse(
                $this->date($booking->getAttribute('starts_on')).' '.$time,
                (string) config('business.timezone'),
            );
            $requiresManager = now((string) config('business.timezone'))->isAfter($startsAt->subHours(24));
        }

        return [
            'can_cancel' => $status === BookingStatus::Pending || ($status === BookingStatus::Approved && ! $requiresManager),
            'requires_manager' => $requiresManager,
            'manager_telegram' => config('business.manager_telegram'),
            'reason' => $this->nullableString($booking->cancellation_reason),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 5);
    }
}
