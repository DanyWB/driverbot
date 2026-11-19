<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'event_type', 'calendar_id', 'google_event_id', 'sync_status',
    'last_requested_revision', 'last_applied_revision', 'sync_lease_token',
    'sync_locked_until', 'last_error', 'last_synced_at', 'last_remote_verified_at',
])]
class GoogleCalendarEvent extends Model
{
    protected $table = 'google_calendar_events';

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_requested_revision' => 'integer',
            'last_applied_revision' => 'integer',
            'sync_locked_until' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_remote_verified_at' => 'immutable_datetime',
        ];
    }
}
