<?php

namespace App\Models;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Shared\Enums\ActorType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['booking_id', 'from_status', 'to_status', 'actor_type', 'actor_admin_id', 'actor_customer_id', 'actor_service_client_id', 'reason', 'context', 'request_id', 'created_at'])]
class BookingStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'booking_status_history';

    /** @return array<string, mixed>|null */
    public function contextValues(): ?array
    {
        $value = $this->getAttribute('context');

        return is_array($value) ? $value : null;
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_admin_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function actorCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'actor_customer_id');
    }

    /** @return BelongsTo<ServiceApiClient, $this> */
    public function actorServiceClient(): BelongsTo
    {
        return $this->belongsTo(ServiceApiClient::class, 'actor_service_client_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
            'actor_type' => ActorType::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Booking status history is immutable.'));
    }
}
