<?php

namespace App\Domain\Bookings\Services;

use App\Domain\Availability\Services\BookingAvailabilityService;
use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\ChangeBookingDatesData;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\Services\BookingPriceSnapshotService;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Domain\Shared\Enums\ActorType;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private readonly BookingStateMachine $stateMachine,
        private readonly BookingStatusMutationGuard $statusGuard,
        private readonly BookingAvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly BookingPriceSnapshotService $snapshots,
        private readonly BookingEventRecorder $events,
    ) {}

    public function create(CreateBookingData $data, BookingActor $actor, ?string $requestId = null): Booking
    {
        if (! in_array($data->initialStatus, [BookingStatus::Pending, BookingStatus::Approved], true)) {
            throw new BookingException('invalid_initial_booking_status', 'A booking must be created as pending or approved.', 422);
        }

        if ($data->initialStatus === BookingStatus::Approved) {
            $this->assertActor($actor, ActorType::Admin);
        }

        $this->assertCustomerOwnership($actor, $data->customerId);

        $period = $this->period($data->startsOn, $data->endsOn);
        $pickupTime = $this->normalizeTime($data->pickupTime);
        $returnTime = $this->normalizeTime($data->returnTime);

        try {
            return DB::transaction(function () use ($data, $actor, $requestId, $period, $pickupTime, $returnTime): Booking {
                if (! Customer::query()->whereKey($data->customerId)->exists()) {
                    throw new BookingException('customer_not_found', 'Customer was not found.', 404, [
                        'customer_id' => $data->customerId,
                    ]);
                }

                $vehicle = $this->availability->lockVehicle($data->vehicleId);
                $this->availability->assertAvailable($vehicle, $data->startsOn, $data->endsOn);
                $quote = $this->pricing->quote($vehicle, $period, $actor->type !== ActorType::Admin);
                $now = now();

                $booking = $this->statusGuard->run(fn (): Booking => Booking::query()->create([
                    'customer_id' => $data->customerId,
                    'vehicle_id' => $vehicle->id,
                    'starts_on' => $data->startsOn,
                    'ends_on' => $data->endsOn,
                    'pickup_time' => $pickupTime,
                    'return_time' => $returnTime,
                    'status' => $data->initialStatus,
                    'source' => $data->source,
                    'client_comment' => $data->clientComment,
                    'admin_note' => $data->adminNote,
                    'deposit_note' => $data->depositNote,
                    'created_by_admin_id' => $actor->adminId,
                    'pending_expires_at' => $data->initialStatus === BookingStatus::Pending
                        ? $now->addHours($this->pendingTtlHours())
                        : null,
                    'approved_at' => $data->initialStatus === BookingStatus::Approved ? $now : null,
                ]));

                $this->snapshots->createAutomatic($booking, $quote, $requestId);
                $this->availability->syncBookingOccupancy($booking);
                $this->events->recordInitialStatus($booking, $actor, ['source' => $data->source->value], $requestId);

                return $booking->fresh(['priceSnapshots', 'occupancy']) ?? $booking;
            }, 3);
        } catch (QueryException $exception) {
            $this->availability->rethrowAsBookingConflict($exception, $data->vehicleId, $data->startsOn, $data->endsOn);
        }
    }

    public function submitProcess(Booking $booking, BookingActor $actor, ?string $requestId = null): Booking
    {
        $this->assertActorIn($actor, [ActorType::Customer, ActorType::Service, ActorType::Admin]);

        try {
            return DB::transaction(function () use ($booking, $actor, $requestId): Booking {
                $locked = $this->lockBooking($booking);
                $this->assertCustomerOwnership($actor, (int) $locked->customer_id);
                $from = $locked->bookingStatus();
                $this->stateMachine->assertCanTransition($from, BookingStatus::Pending);
                $startsOn = $this->dateValue($locked, 'starts_on');
                $endsOn = $this->dateValue($locked, 'ends_on');
                $vehicle = $this->availability->lockVehicle((int) $locked->vehicle_id);
                $this->availability->assertAvailable($vehicle, $startsOn, $endsOn, $locked->id);
                $quote = $this->pricing->quote($vehicle, $this->period($startsOn, $endsOn), $actor->type !== ActorType::Admin);

                $this->saveStatus($locked, BookingStatus::Pending, [
                    'pending_expires_at' => now()->addHours($this->pendingTtlHours()),
                ]);
                $this->snapshots->createAutomatic($locked, $quote, $requestId);
                $this->availability->syncBookingOccupancy($locked);
                $this->events->recordStatus($locked, $from, BookingStatus::Pending, $actor, requestId: $requestId);

                return $locked->fresh(['priceSnapshots', 'occupancy']) ?? $locked;
            }, 3);
        } catch (QueryException $exception) {
            $this->availability->rethrowAsBookingConflict(
                $exception,
                (int) $booking->vehicle_id,
                $this->dateValue($booking, 'starts_on'),
                $this->dateValue($booking, 'ends_on'),
            );
        }
    }

    public function approve(Booking $booking, BookingActor $actor, ?string $requestId = null): Booking
    {
        $this->assertActor($actor, ActorType::Admin);

        return $this->transition($booking, BookingStatus::Approved, $actor, [
            'approved_at' => now(),
            'pending_expires_at' => null,
        ], requestId: $requestId);
    }

    public function activate(Booking $booking, BookingActor $actor, ?string $requestId = null): Booking
    {
        $this->assertActor($actor, ActorType::Admin);

        return $this->transition($booking, BookingStatus::Active, $actor, [
            'activated_at' => now(),
        ], requestId: $requestId);
    }

    public function complete(Booking $booking, BookingActor $actor, ?string $requestId = null): Booking
    {
        $this->assertActor($actor, ActorType::Admin);

        return $this->transition($booking, BookingStatus::Completed, $actor, [
            'completed_at' => now(),
        ], requestId: $requestId);
    }

    public function cancelByAdmin(Booking $booking, BookingActor $actor, string $reason, ?string $requestId = null): Booking
    {
        $this->assertActor($actor, ActorType::Admin);

        return $this->transition($booking, BookingStatus::Cancelled, $actor, [
            'cancelled_at' => now(),
            'cancellation_reason' => $this->requiredReason($reason),
        ], $reason, requestId: $requestId);
    }

    public function cancelByClient(
        Booking $booking,
        BookingActor $actor,
        ?string $reason = null,
        ?DateTimeInterface $at = null,
        ?string $requestId = null,
    ): Booking {
        $this->assertActor($actor, ActorType::Customer);

        return DB::transaction(function () use ($booking, $actor, $reason, $at, $requestId): Booking {
            $locked = $this->lockBooking($booking);

            if ($actor->customerId !== (int) $locked->customer_id) {
                throw new BookingException('booking_customer_mismatch', 'The booking belongs to another customer.', 403);
            }

            if ($locked->bookingStatus() === BookingStatus::Approved) {
                $this->assertClientCancellationWindow($locked, $at);
            }

            return $this->transitionLocked($locked, BookingStatus::CancelledByClient, $actor, [
                'cancelled_at' => now(),
                'cancellation_reason' => $this->optionalReason($reason),
            ], $this->optionalReason($reason), requestId: $requestId);
        }, 3);
    }

    public function expire(Booking $booking, ?DateTimeInterface $at = null, ?string $requestId = null): Booking
    {
        return DB::transaction(function () use ($booking, $at, $requestId): Booking {
            $locked = $this->lockBooking($booking);
            $expiresAt = $locked->pendingExpiresAt();
            $now = CarbonImmutable::instance($at ?? now());

            if ($locked->bookingStatus() !== BookingStatus::Pending || $expiresAt === null || $expiresAt->isAfter($now)) {
                throw new BookingException('booking_not_due_for_expiry', 'Booking is not due for expiry.', 409);
            }

            return $this->transitionLocked($locked, BookingStatus::Expired, BookingActor::system(), [
                'expired_at' => $now,
            ], requestId: $requestId);
        }, 3);
    }

    public function markNoShow(
        Booking $booking,
        BookingActor $actor,
        ?string $reason = null,
        ?DateTimeInterface $at = null,
        ?string $requestId = null,
    ): Booking {
        $this->assertActor($actor, ActorType::Admin);

        return DB::transaction(function () use ($booking, $actor, $reason, $at, $requestId): Booking {
            $locked = $this->lockBooking($booking);
            $this->stateMachine->assertCanTransition($locked->bookingStatus(), BookingStatus::NoShow);
            $this->assertRentalStarted($locked, $at);

            return $this->transitionLocked($locked, BookingStatus::NoShow, $actor, [
                'no_show_at' => CarbonImmutable::instance($at ?? now()),
                'no_show_reason' => $this->optionalReason($reason),
            ], $this->optionalReason($reason), requestId: $requestId);
        }, 3);
    }

    public function changeDates(
        Booking $booking,
        ChangeBookingDatesData $data,
        BookingActor $actor,
        ?string $requestId = null,
    ): Booking {
        $this->assertActor($actor, ActorType::Admin);
        $period = $this->period($data->startsOn, $data->endsOn);
        $pickupTime = $this->normalizeTime($data->pickupTime);
        $returnTime = $this->normalizeTime($data->returnTime);

        try {
            return DB::transaction(function () use ($booking, $data, $actor, $requestId, $period, $pickupTime, $returnTime): Booking {
                $locked = $this->lockBooking($booking);

                if (! in_array($locked->bookingStatus(), [BookingStatus::Pending, BookingStatus::Approved, BookingStatus::Active], true)) {
                    throw new BookingException('booking_dates_locked', 'Dates cannot be changed in the current booking status.', 409, [
                        'status' => $locked->bookingStatus()->value,
                    ]);
                }

                $vehicle = $this->availability->lockVehicle((int) $locked->vehicle_id);
                $this->availability->assertAvailable($vehicle, $data->startsOn, $data->endsOn, $locked->id);
                $quote = $this->pricing->quote($vehicle, $period, false);
                $oldValues = $this->bookingDateValues($locked);

                $locked->forceFill([
                    'starts_on' => $data->startsOn,
                    'ends_on' => $data->endsOn,
                    'pickup_time' => $pickupTime,
                    'return_time' => $returnTime,
                ])->save();

                $this->availability->syncBookingOccupancy($locked);
                $this->snapshots->createAutomatic($locked, $quote, $requestId);
                $this->events->recordDatesChanged($locked, $actor, $oldValues, $this->bookingDateValues($locked), $requestId);

                return $locked->fresh(['priceSnapshots', 'occupancy']) ?? $locked;
            }, 3);
        } catch (QueryException $exception) {
            $this->availability->rethrowAsBookingConflict($exception, (int) $booking->vehicle_id, $data->startsOn, $data->endsOn);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     */
    private function transition(
        Booking $booking,
        BookingStatus $to,
        BookingActor $actor,
        array $attributes = [],
        ?string $reason = null,
        array $context = [],
        ?string $requestId = null,
    ): Booking {
        try {
            return DB::transaction(function () use ($booking, $to, $actor, $attributes, $reason, $context, $requestId): Booking {
                return $this->transitionLocked($this->lockBooking($booking), $to, $actor, $attributes, $reason, $context, $requestId);
            }, 3);
        } catch (QueryException $exception) {
            $this->availability->rethrowAsBookingConflict(
                $exception,
                (int) $booking->vehicle_id,
                $this->dateValue($booking, 'starts_on'),
                $this->dateValue($booking, 'ends_on'),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     */
    private function transitionLocked(
        Booking $booking,
        BookingStatus $to,
        BookingActor $actor,
        array $attributes = [],
        ?string $reason = null,
        array $context = [],
        ?string $requestId = null,
    ): Booking {
        $from = $booking->bookingStatus();
        $this->stateMachine->assertCanTransition($from, $to);
        $this->saveStatus($booking, $to, $attributes);
        $this->availability->syncBookingOccupancy($booking);
        $this->events->recordStatus($booking, $from, $to, $actor, $reason, $context, $requestId);

        return $booking->fresh(['occupancy']) ?? $booking;
    }

    /** @param array<string, mixed> $attributes */
    private function saveStatus(Booking $booking, BookingStatus $status, array $attributes): void
    {
        $this->statusGuard->run(function () use ($booking, $status, $attributes): void {
            $booking->forceFill(['status' => $status, ...$attributes])->save();
        });
    }

    private function lockBooking(Booking $booking): Booking
    {
        $locked = Booking::query()->lockForUpdate()->find($booking->id);

        if (! $locked instanceof Booking) {
            throw new BookingException('booking_not_found', 'Booking was not found.', 404);
        }

        return $locked;
    }

    private function assertActor(BookingActor $actor, ActorType $required): void
    {
        if ($actor->type !== $required) {
            throw new BookingException('booking_actor_forbidden', 'Actor cannot perform this booking action.', 403, [
                'required_actor' => $required->value,
                'actual_actor' => $actor->type->value,
            ]);
        }
    }

    /** @param list<ActorType> $allowed */
    private function assertActorIn(BookingActor $actor, array $allowed): void
    {
        if (! in_array($actor->type, $allowed, true)) {
            throw new BookingException('booking_actor_forbidden', 'Actor cannot perform this booking action.', 403);
        }
    }

    private function assertCustomerOwnership(BookingActor $actor, int $customerId): void
    {
        if ($actor->type === ActorType::Customer && $actor->customerId !== $customerId) {
            throw new BookingException('booking_customer_mismatch', 'The booking belongs to another customer.', 403);
        }
    }

    private function assertClientCancellationWindow(Booking $booking, ?DateTimeInterface $at): void
    {
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($this->businessTimezone());
        $cutoff = $this->bookingStartsAt($booking)->subHours(24);

        if ($now->isAfter($cutoff)) {
            throw new BookingException(
                'client_cancellation_requires_manager',
                'Less than 24 hours remain before rental start. Contact the manager directly.',
                409,
                ['manager_telegram' => config('business.manager_telegram')],
            );
        }
    }

    private function assertRentalStarted(Booking $booking, ?DateTimeInterface $at): void
    {
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($this->businessTimezone());

        if ($now->isBefore($this->bookingStartsAt($booking))) {
            throw new BookingException('no_show_too_early', 'No-show can only be recorded after rental start.', 409);
        }
    }

    private function bookingStartsAt(Booking $booking): CarbonImmutable
    {
        $time = (string) ($booking->getRawOriginal('pickup_time') ?: '00:00:00');

        if (strlen($time) === 5) {
            $time .= ':00';
        }
        $startsAt = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $this->dateValue($booking, 'starts_on').' '.$time,
            $this->businessTimezone(),
        );

        if (! $startsAt instanceof CarbonImmutable) {
            throw new BookingException('invalid_booking_start', 'Booking start date or time is invalid.', 500);
        }

        return $startsAt;
    }

    private function period(string $startsOn, string $endsOn): RentalPeriod
    {
        try {
            return RentalPeriod::fromStrings($startsOn, $endsOn, $this->businessTimezone());
        } catch (InvalidArgumentException $exception) {
            throw new BookingException('invalid_rental_period', $exception->getMessage(), 422, previous: $exception);
        }
    }

    private function normalizeTime(?string $time): ?string
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $time = trim($time);

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time) === 1) {
            return strlen($time) === 5 ? "{$time}:00" : $time;
        }

        throw new BookingException('invalid_booking_time', 'Booking time must use HH:MM or HH:MM:SS format.', 422);
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new BookingException('booking_reason_required', 'A reason is required for this booking action.', 422);
        }

        return $reason;
    }

    private function optionalReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === '' ? null : $reason;
    }

    /** @return array{starts_on: string, ends_on: string, pickup_time: string|null, return_time: string|null} */
    private function bookingDateValues(Booking $booking): array
    {
        return [
            'starts_on' => $this->dateValue($booking, 'starts_on'),
            'ends_on' => $this->dateValue($booking, 'ends_on'),
            'pickup_time' => $this->nullableString($booking->getRawOriginal('pickup_time')),
            'return_time' => $this->nullableString($booking->getRawOriginal('return_time')),
        ];
    }

    private function dateValue(Booking $booking, string $attribute): string
    {
        $value = $booking->getAttribute($attribute);

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = (string) $booking->getRawOriginal($attribute);

        return substr($raw, 0, 10);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function pendingTtlHours(): int
    {
        return max(1, (int) config('business.pending_ttl_hours', 24));
    }

    private function businessTimezone(): string
    {
        return (string) config('business.timezone', 'Asia/Bangkok');
    }
}
