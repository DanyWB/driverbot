<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Models\Booking;
use App\Models\CustomerIdentity;
use App\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class NotificationOutboxService
{
    /** @param array<string, mixed> $payload */
    public function enqueue(
        string $deduplicationKey,
        string $eventType,
        string $channel,
        string $recipient,
        array $payload,
        ?DateTimeInterface $availableAt = null,
    ): NotificationOutbox {
        return NotificationOutbox::query()->firstOrCreate([
            'deduplication_key' => $deduplicationKey,
        ], [
            'event_type' => $eventType,
            'channel' => $channel,
            'recipient' => $recipient,
            'payload' => $payload,
            'status' => NotificationOutbox::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function enqueueCustomer(
        Booking $booking,
        string $eventType,
        string $deduplicationKey,
        array $payload,
        ?DateTimeInterface $availableAt = null,
    ): ?NotificationOutbox {
        $identity = $this->telegramIdentity($booking);

        if (! $identity instanceof CustomerIdentity) {
            return null;
        }

        return $this->enqueue($deduplicationKey, $eventType, 'telegram', (string) $identity->external_id, [
            'booking_public_id' => (string) $booking->public_id,
            ...$payload,
        ], $availableAt);
    }

    public function schedulePickupReminders(Booking $booking): void
    {
        $version = max(0, (int) $booking->reminder_version);
        $this->discardPickupReminders($booking, 'Superseded by booking reminder version.', $version);

        if ($booking->bookingStatus() !== BookingStatus::Approved || ! $this->telegramIdentity($booking) instanceof CustomerIdentity) {
            return;
        }

        $timezone = (string) config('business.timezone', 'Asia/Bangkok');
        $now = CarbonImmutable::now($timezone);
        $startsOn = $this->dateValue($booking, 'starts_on');
        $startOfDay = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', "{$startsOn} 00:00:00", $timezone);

        if (! $startOfDay instanceof CarbonImmutable || $startOfDay->isBefore($now->startOfDay())) {
            $this->discardPickupReminders($booking, 'The rental start is already in the past.');

            return;
        }

        $pickupAt = $this->pickupAt($booking, $timezone);

        if ($pickupAt instanceof CarbonImmutable && ! $pickupAt->isAfter($now)) {
            $this->discardPickupReminders($booking, 'The rental pickup time has passed.');

            return;
        }

        $dayTarget = $this->dayReminderAt($startOfDay, $pickupAt, $timezone);
        $hourTarget = $pickupAt?->subHour();
        $hourIsAlreadyDue = $hourTarget instanceof CarbonImmutable && ! $hourTarget->isAfter($now);
        $dayIsAlreadyDue = ! $dayTarget->isAfter($now);

        if (! ($hourIsAlreadyDue && $dayIsAlreadyDue)) {
            $this->enqueueReminder(
                $booking,
                $version,
                'pickup_day',
                'booking.reminder.pickup_day',
                $dayTarget->isBefore($now) ? $now : $dayTarget,
            );
        }

        if ($hourTarget instanceof CarbonImmutable) {
            $this->enqueueReminder(
                $booking,
                $version,
                'pickup_one_hour',
                'booking.reminder.pickup_one_hour',
                $hourTarget->isBefore($now) ? $now : $hourTarget,
            );
        }
    }

    public function discardPickupReminders(Booking $booking, string $reason, ?int $exceptVersion = null): int
    {
        $query = NotificationOutbox::query()
            ->where('deduplication_key', 'like', "booking:{$booking->public_id}:reminder:%")
            ->whereIn('status', [NotificationOutbox::STATUS_PENDING, NotificationOutbox::STATUS_PROCESSING]);

        if ($exceptVersion !== null) {
            $query->where('deduplication_key', 'not like', "booking:{$booking->public_id}:reminder:v{$exceptVersion}:%");
        }

        return $query->update([
            'status' => NotificationOutbox::STATUS_DISCARDED,
            'processing_started_at' => null,
            'discarded_at' => now(),
            'last_error' => $reason,
            'updated_at' => now(),
        ]);
    }

    private function enqueueReminder(
        Booking $booking,
        int $version,
        string $type,
        string $eventType,
        CarbonImmutable $availableAt,
    ): void {
        $this->enqueueCustomer(
            $booking,
            $eventType,
            "booking:{$booking->public_id}:reminder:v{$version}:{$type}",
            [
                'reminder_version' => $version,
                'starts_on' => $this->dateValue($booking, 'starts_on'),
                'pickup_time' => $this->nullableString($booking->getRawOriginal('pickup_time')),
                'scheduled_for' => $availableAt->toIso8601String(),
            ],
            $availableAt,
        );
    }

    private function telegramIdentity(Booking $booking): ?CustomerIdentity
    {
        return CustomerIdentity::query()
            ->where('customer_id', $booking->customer_id)
            ->where('provider', IdentityProvider::Telegram->value)
            ->first();
    }

    private function dayReminderAt(
        CarbonImmutable $startOfDay,
        ?CarbonImmutable $pickupAt,
        string $timezone,
    ): CarbonImmutable {
        $configured = trim((string) config('notifications.reminders.day_time', '08:00'));

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $configured) !== 1) {
            $configured = '08:00';
        }

        $target = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $startOfDay->format('Y-m-d')." {$configured}:00",
            $timezone,
        );
        $target = $target instanceof CarbonImmutable ? $target : $startOfDay->addHours(8);

        if ($pickupAt instanceof CarbonImmutable && ! $target->isBefore($pickupAt)) {
            $candidate = $pickupAt->subHours(2);

            return $candidate->isBefore($startOfDay) ? $startOfDay : $candidate;
        }

        return $target;
    }

    private function pickupAt(Booking $booking, string $timezone): ?CarbonImmutable
    {
        $time = $this->nullableString($booking->getRawOriginal('pickup_time'));

        if ($time === null) {
            return null;
        }

        if (strlen($time) === 5) {
            $time .= ':00';
        }

        $value = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $this->dateValue($booking, 'starts_on')." {$time}",
            $timezone,
        );

        return $value instanceof CarbonImmutable ? $value : null;
    }

    private function dateValue(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $booking->getRawOriginal($attribute), 0, 10);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
