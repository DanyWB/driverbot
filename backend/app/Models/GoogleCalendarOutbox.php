<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'event_type', 'operation', 'event_revision', 'idempotency_key',
    'status', 'retry_count', 'available_at', 'lease_token', 'locked_until',
    'last_error', 'processed_at',
])]
class GoogleCalendarOutbox extends Model
{
    protected $table = 'google_calendar_outbox';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_revision' => 'integer',
            'retry_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
