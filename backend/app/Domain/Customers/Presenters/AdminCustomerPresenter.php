<?php

namespace App\Domain\Customers\Presenters;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerDocument;
use App\Models\CustomerIdentity;
use DateTimeInterface;

class AdminCustomerPresenter
{
    /** @return array<string, mixed> */
    public function listItem(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'locale' => (string) $customer->locale,
            'contacts' => $customer->contacts->map(fn (CustomerContact $contact): array => $this->contact($contact))->values()->all(),
            'bookings_count' => (int) ($customer->getAttribute('bookings_count') ?? 0),
            'documents_count' => (int) ($customer->getAttribute('documents_count') ?? 0),
            'latest_booking' => $customer->latestBooking === null ? null : [
                'public_id' => (string) $customer->latestBooking->public_id,
                'status' => (string) $customer->latestBooking->getRawOriginal('status'),
                'starts_on' => $this->date($customer->latestBooking->getAttribute('starts_on')),
                'vehicle_name' => (string) $customer->latestBooking->vehicle->name,
            ],
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Customer $customer): array
    {
        $private = $customer->privateData();

        return [
            ...$this->listItem($customer),
            'internal_note' => $customer->internal_note,
            'passport_number' => isset($private['passport_number']) ? (string) $private['passport_number'] : null,
            'identities' => $customer->identities->map(fn (CustomerIdentity $identity): array => [
                'id' => (int) $identity->id,
                'provider' => (string) $identity->getRawOriginal('provider'),
                'external_id' => (string) $identity->external_id,
            ])->values()->all(),
            'documents' => $customer->documents->map(fn (CustomerDocument $document): array => [
                'id' => (int) $document->id,
                'type' => (string) $document->type,
                'filename' => (string) $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => is_numeric($document->file_size) ? (int) $document->file_size : null,
                'booking_public_id' => $document->booking?->public_id,
                'download_url' => route('documents.download', $document),
                'created_at' => $document->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function contact(CustomerContact $contact): array
    {
        return [
            'id' => (int) $contact->id,
            'type' => (string) $contact->getRawOriginal('type'),
            'value' => (string) $contact->value,
            'is_primary' => (bool) $contact->is_primary,
            'verified_at' => $this->dateTime($contact->getAttribute('verified_at')),
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
