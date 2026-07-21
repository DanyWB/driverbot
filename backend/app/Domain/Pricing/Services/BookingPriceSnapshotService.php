<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Bookings\Services\BookingEventRecorder;
use App\Domain\Pricing\Data\PriceQuote;
use App\Domain\Pricing\Enums\PricingSource;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Shared\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BookingPriceSnapshotService
{
    public function __construct(private readonly BookingEventRecorder $bookingEvents) {}

    public function createAutomatic(Booking $booking, PriceQuote $quote, ?string $requestId = null): BookingPriceSnapshot
    {
        if ($booking->vehicle_id !== $quote->vehicleId) {
            throw new InvalidArgumentException('The quote belongs to a different vehicle.');
        }

        return DB::transaction(function () use ($booking, $quote, $requestId): BookingPriceSnapshot {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $previous = $lockedBooking->priceSnapshots()->first();

            $snapshot = $lockedBooking->priceSnapshots()->create([
                'version' => $this->nextVersion($lockedBooking),
                'total_days' => $quote->totalDays,
                'tier_key' => $quote->tier,
                'calculated_total' => $quote->calculatedTotal,
                'rounded_total' => $quote->roundedTotal,
                'manual_total' => null,
                'final_total' => $quote->finalTotal,
                'currency' => $quote->currency,
                'breakdown' => array_map(fn ($item): array => $item->toArray(), $quote->breakdown),
                'pricing_source' => PricingSource::Automatic,
                'overridden_by_admin_id' => null,
                'override_reason' => null,
                'calculated_at' => now(),
            ]);

            $this->writeAudit(
                booking: $lockedBooking,
                action: 'booking.price_calculated',
                snapshot: $snapshot,
                actorType: ActorType::System,
                requestId: $requestId,
                previous: $previous,
            );

            return $snapshot;
        });
    }

    public function override(
        Booking $booking,
        int $manualTotal,
        string $reason,
        User $admin,
        ?string $requestId = null,
    ): BookingPriceSnapshot {
        $reason = trim($reason);

        if ($manualTotal < 0) {
            throw new InvalidArgumentException('Manual total cannot be negative.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required for a manual price override.');
        }

        return DB::transaction(function () use ($booking, $manualTotal, $reason, $admin, $requestId): BookingPriceSnapshot {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $previous = $lockedBooking->priceSnapshots()->first();

            if (! $previous instanceof BookingPriceSnapshot) {
                throw new PricingException('price_snapshot_missing', 'Cannot override a booking without a calculated price.', [
                    'booking_id' => $lockedBooking->id,
                ]);
            }

            $snapshot = $lockedBooking->priceSnapshots()->create([
                'version' => $this->nextVersion($lockedBooking),
                'total_days' => $previous->total_days,
                'tier_key' => $previous->tier_key,
                'calculated_total' => $previous->calculated_total,
                'rounded_total' => $previous->rounded_total,
                'manual_total' => $manualTotal,
                'final_total' => $manualTotal,
                'currency' => $previous->currency,
                'breakdown' => $previous->breakdown,
                'pricing_source' => PricingSource::ManualOverride,
                'overridden_by_admin_id' => $admin->id,
                'override_reason' => $reason,
                'calculated_at' => now(),
            ]);

            $this->writeAudit(
                booking: $lockedBooking,
                action: 'booking.price_overridden',
                snapshot: $snapshot,
                actorType: ActorType::Admin,
                requestId: $requestId,
                previous: $previous,
                admin: $admin,
            );
            $this->bookingEvents->recordPriceChanged($lockedBooking, $snapshot);

            return $snapshot;
        });
    }

    private function nextVersion(Booking $booking): int
    {
        return ((int) $booking->priceSnapshots()->max('version')) + 1;
    }

    private function writeAudit(
        Booking $booking,
        string $action,
        BookingPriceSnapshot $snapshot,
        ActorType $actorType,
        ?string $requestId,
        ?BookingPriceSnapshot $previous = null,
        ?User $admin = null,
    ): void {
        AuditLog::query()->create([
            'actor_type' => $actorType,
            'actor_admin_id' => $admin?->id,
            'subject_type' => Booking::class,
            'subject_id' => (string) $booking->public_id,
            'action' => $action,
            'old_values' => $previous === null ? null : $this->snapshotValues($previous),
            'new_values' => $this->snapshotValues($snapshot),
            'request_id' => $requestId,
        ]);
    }

    /** @return array<string, int|string|null> */
    private function snapshotValues(BookingPriceSnapshot $snapshot): array
    {
        $manualTotal = $snapshot->getAttribute('manual_total');
        $overrideReason = $snapshot->getAttribute('override_reason');

        return [
            'snapshot_id' => (int) $snapshot->id,
            'version' => (int) $snapshot->version,
            'calculated_total' => (string) $snapshot->calculated_total,
            'rounded_total' => (int) $snapshot->rounded_total,
            'manual_total' => is_numeric($manualTotal) ? (int) $manualTotal : null,
            'final_total' => (int) $snapshot->final_total,
            'currency' => (string) $snapshot->currency,
            'pricing_source' => $snapshot->pricingSource()->value,
            'override_reason' => is_string($overrideReason) ? $overrideReason : null,
        ];
    }
}
