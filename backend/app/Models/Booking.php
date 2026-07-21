<?php

namespace App\Models;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Services\BookingStatusMutationGuard;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable(['customer_id', 'vehicle_id', 'starts_on', 'ends_on', 'pickup_time', 'return_time', 'status', 'source', 'client_comment', 'admin_note', 'cancellation_reason', 'no_show_reason', 'deposit_note', 'created_by_admin_id', 'pending_expires_at', 'approved_at', 'activated_at', 'completed_at', 'cancelled_at', 'expired_at', 'no_show_at'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<BookingPriceSnapshot, $this> */
    public function priceSnapshots(): HasMany
    {
        return $this->hasMany(BookingPriceSnapshot::class)->orderByDesc('version');
    }

    /** @return HasOne<VehicleOccupancy, $this> */
    public function occupancy(): HasOne
    {
        return $this->hasOne(VehicleOccupancy::class);
    }

    /** @return HasMany<BookingStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('created_at');
    }

    public function bookingStatus(): BookingStatus
    {
        return BookingStatus::from((string) $this->getRawOriginal('status'));
    }

    public function pendingExpiresAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('pending_expires_at');

        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new LogicException('Booking pending expiry is not a date-time value.');
        }

        return CarbonImmutable::instance($value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'pending_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $value = $booking->getAttribute('status');
            $status = $value instanceof BookingStatus
                ? $value
                : BookingStatus::tryFrom((string) $value) ?? BookingStatus::Process;

            if ($status !== BookingStatus::Process && ! app(BookingStatusMutationGuard::class)->isAllowed()) {
                throw new LogicException('A blocking booking can only be created through the booking service.');
            }
        });

        static::updating(function (Booking $booking): void {
            if ($booking->isDirty('status') && ! app(BookingStatusMutationGuard::class)->isAllowed()) {
                throw new LogicException('Booking status can only be changed through the booking state machine.');
            }
        });
    }
}
