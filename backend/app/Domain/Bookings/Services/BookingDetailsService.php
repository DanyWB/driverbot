<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Availability\Services\BookingAvailabilityService;
use App\Domain\Shared\Services\AdminAuditService;
use App\Models\Booking;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingDetailsService
{
    public function __construct(private readonly BookingAvailabilityService $availability, private readonly AdminAuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function update(Booking $booking, array $data, User $admin, ?string $requestId = null): Booking
    {
        return DB::transaction(function () use ($booking, $data, $admin, $requestId): Booking {
            $this->availability->lockVehicle((int) $booking->vehicle_id);
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if ((int) $locked->details_version !== (int) $data['expected_details_version']) {
                throw ValidationException::withMessages(['expected_details_version' => 'Booking details changed. Reload before saving; your draft has not been applied.']);
            }
            $values = [
                'contact_name' => trim((string) $data['contact_name']),
                'contact_phone' => trim((string) ($data['contact_phone'] ?? '')),
                'contact_telegram' => ltrim(trim((string) ($data['contact_telegram'] ?? '')), '@'),
            ];
            foreach (['prepayment', 'deposit'] as $payment) {
                $input = $data['payments'][$payment];
                $amount = $input['amount'] === null ? null : (string) BigDecimal::of((string) $input['amount'])->toScale(2);
                $currency = (string) $input['currency'];
                $changedAmount = $locked->getAttribute($payment.'_amount') !== $amount
                    || $locked->getAttribute($payment.'_currency') !== $currency;
                $previouslyReceived = $locked->getAttribute($payment.'_received_at') !== null;
                // A receipt for an old amount/currency never silently carries over.
                $received = (bool) $input['received'] && $amount !== null && ! ($changedAmount && $previouslyReceived);
                $values[$payment.'_amount'] = $amount;
                $values[$payment.'_currency'] = $currency;
                $values[$payment.'_received_at'] = $received ? ($locked->getAttribute($payment.'_received_at') ?? now()) : null;
                $values[$payment.'_received_by_admin_id'] = $received ? ($locked->getAttribute($payment.'_received_by_admin_id') ?? $admin->id) : null;
            }
            $old = $locked->only(array_keys($values));
            $locked->forceFill($values);
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->forceFill(['details_version' => (int) $locked->details_version + 1])->save();
            $this->audit->record($admin, $locked, 'booking.details_updated', $old, $locked->only(array_keys($values)), $requestId);

            return $locked;
        }, 3);
    }
}
