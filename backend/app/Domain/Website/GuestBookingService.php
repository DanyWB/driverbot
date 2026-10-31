<?php

namespace App\Domain\Website;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Services\BookingService;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestBookingService
{
    /** @param array<string, mixed> $data */
    public function create(int $vehicleId, string $locale, string $ownerHash, array $data): Booking
    {
        $payload = collect($data)->except(['company', 'idempotency_key'])->sortKeys()->all();
        $payloadHash = hash('sha256', json_encode(['vehicle' => $vehicleId, 'payload' => $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($vehicleId, $locale, $ownerHash, $data, $payloadHash): Booking {
            // A durable uniqueness boundary survives retries and session-lock backend changes.
            DB::table('website_booking_requests')->insertOrIgnore([
                'owner_hash' => $ownerHash, 'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $payloadHash, 'contact_channel' => $data['contact_channel'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $submission = DB::table('website_booking_requests')->where('owner_hash', $ownerHash)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($submission === null || ! hash_equals($submission->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages(['idempotency_key' => __('website.idempotency_conflict', [], $locale)]);
            }
            if ($submission->booking_id !== null) {
                return Booking::query()->whereKey($submission->booking_id)->firstOrFail();
            }
            app(PublicCatalog::class)->find($vehicleId);
            // A user-provided contact is not identity. Do not merge with existing customers.
            $customer = Customer::query()->create(['name' => $data['name'], 'locale' => $locale]);
            $customer->contacts()->create([
                'type' => $data['contact_channel'], 'value' => $data['contact_value'],
                'normalized_value' => mb_strtolower(trim($data['contact_value'])), 'is_primary' => true, 'verified_at' => null,
            ]);
            $booking = app(BookingService::class)->create(new CreateBookingData(
                customerId: $customer->id, vehicleId: $vehicleId,
                startsOn: $data['starts_on'], endsOn: $data['ends_on'], source: BookingSource::Website,
                pickupTime: '12:00', returnTime: '12:00', clientComment: $data['client_comment'] ?? null,
                termsAcceptedAt: now(), termsVersion: config('website.terms_version'),
            ), BookingActor::customer($customer->id));
            DB::table('website_booking_requests')->where('id', $submission->id)->update(['booking_id' => $booking->id, 'updated_at' => now()]);

            return $booking;
        }, 3);
    }
}
