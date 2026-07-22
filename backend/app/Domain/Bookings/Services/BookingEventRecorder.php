<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\BookingStatusHistory;
use App\Models\CustomerIdentity;
use App\Models\NotificationOutbox;

class BookingEventRecorder
{
    /** @param array<string, mixed> $context */
    public function recordInitialStatus(
        Booking $booking,
        BookingActor $actor,
        array $context = [],
        ?string $requestId = null,
    ): void {
        $this->recordStatus($booking, null, $booking->bookingStatus(), $actor, null, $context, $requestId);
    }

    /** @param array<string, mixed> $context */
    public function recordStatus(
        Booking $booking,
        ?BookingStatus $from,
        BookingStatus $to,
        BookingActor $actor,
        ?string $reason = null,
        array $context = [],
        ?string $requestId = null,
    ): void {
        $history = BookingStatusHistory::query()->create([
            'booking_id' => $booking->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor->type,
            'actor_admin_id' => $actor->adminId,
            'actor_customer_id' => $actor->customerId,
            'actor_service_client_id' => $actor->serviceClientId,
            'reason' => $reason,
            'context' => $context === [] ? null : $context,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            ...$this->auditActor($actor),
            'subject_type' => Booking::class,
            'subject_id' => (string) $booking->public_id,
            'action' => 'booking.status_changed',
            'old_values' => $from === null ? null : ['status' => $from->value],
            'new_values' => ['status' => $to->value, ...$context],
            'request_id' => $requestId,
        ]);

        $this->enqueueStatusNotifications($booking, $history, $to, $reason);
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordDatesChanged(
        Booking $booking,
        BookingActor $actor,
        array $oldValues,
        array $newValues,
        ?string $requestId = null,
    ): void {
        $snapshot = $booking->priceSnapshots()->first();
        $audit = AuditLog::query()->create([
            ...$this->auditActor($actor),
            'subject_type' => Booking::class,
            'subject_id' => (string) $booking->public_id,
            'action' => 'booking.dates_changed',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'request_id' => $requestId,
        ]);

        $this->enqueueCustomer($booking, 'booking.dates_changed', "booking:{$booking->public_id}:dates:{$audit->id}", [
            'old_dates' => $oldValues,
            'new_dates' => $newValues,
            'final_total' => $snapshot instanceof BookingPriceSnapshot ? (int) $snapshot->final_total : null,
            'currency' => $snapshot instanceof BookingPriceSnapshot ? (string) $snapshot->currency : null,
        ]);
    }

    public function recordPriceChanged(Booking $booking, BookingPriceSnapshot $snapshot): void
    {
        $this->enqueueCustomer(
            $booking,
            'booking.price_changed',
            "booking:{$booking->public_id}:price:{$snapshot->id}",
            [
                'snapshot_version' => (int) $snapshot->version,
                'final_total' => (int) $snapshot->final_total,
                'currency' => (string) $snapshot->currency,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function auditActor(BookingActor $actor): array
    {
        return [
            'actor_type' => $actor->type,
            'actor_admin_id' => $actor->adminId,
            'actor_customer_id' => $actor->customerId,
            'actor_service_client_id' => $actor->serviceClientId,
        ];
    }

    private function enqueueStatusNotifications(
        Booking $booking,
        BookingStatusHistory $history,
        BookingStatus $status,
        ?string $reason,
    ): void {
        $customerEvents = [
            BookingStatus::Pending,
            BookingStatus::Approved,
            BookingStatus::Cancelled,
            BookingStatus::Expired,
        ];

        if (in_array($status, $customerEvents, true)) {
            $this->enqueueCustomer($booking, "booking.{$status->value}", "booking:{$booking->public_id}:status:{$history->id}:customer", [
                'status' => $status->value,
                'reason' => $reason,
            ]);
        }

        if (in_array($status, [BookingStatus::Pending, BookingStatus::CancelledByClient], true)) {
            $this->enqueue(
                "booking:{$booking->public_id}:status:{$history->id}:admin",
                "booking.{$status->value}",
                'internal',
                'admin',
                [
                    'booking_public_id' => $booking->public_id,
                    'status' => $status->value,
                    'reason' => $reason,
                ],
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function enqueueCustomer(Booking $booking, string $eventType, string $deduplicationKey, array $payload): void
    {
        $identity = CustomerIdentity::query()
            ->where('customer_id', $booking->customer_id)
            ->where('provider', IdentityProvider::Telegram->value)
            ->first();

        if (! $identity instanceof CustomerIdentity) {
            return;
        }

        $this->enqueue($deduplicationKey, $eventType, 'telegram', (string) $identity->external_id, [
            'booking_public_id' => $booking->public_id,
            ...$payload,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(string $deduplicationKey, string $eventType, string $channel, string $recipient, array $payload): void
    {
        NotificationOutbox::query()->firstOrCreate([
            'deduplication_key' => $deduplicationKey,
        ], [
            'event_type' => $eventType,
            'channel' => $channel,
            'recipient' => $recipient,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
